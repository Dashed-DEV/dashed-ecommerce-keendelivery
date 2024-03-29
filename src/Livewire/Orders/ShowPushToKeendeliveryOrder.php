<?php

namespace Dashed\DashedEcommerceKeendelivery\Livewire\Orders;

use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedEcommerceKeendelivery\Classes\KeenDelivery;
use Dashed\DashedEcommerceKeendelivery\Mail\TrackandTraceMail;
use Dashed\DashedEcommerceKeendelivery\Models\KeendeliveryOrder;
use Dashed\DashedEcommerceKeendelivery\Models\KeendeliveryShippingMethod;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class ShowPushToKeendeliveryOrder extends Component implements HasForms, HasActions
{
    use InteractsWithForms;
    use InteractsWithActions;

    public Order $order;

    public function mount(Order $order)
    {
        $this->order = $order;
    }

    public function render()
    {
        return view('dashed-ecommerce-core::orders.components.plain-action');
    }

    public function action(): Action
    {
        return Action::make('action')
            ->label('Verstuur naar KeenDelivery')
            ->color('primary')
            ->fillForm(function () {
                $data = [];

                $shippingMethods = KeendeliveryShippingMethod::where('enabled', 1)->where('site_id', $this->order->site_id)->get();
                foreach ($shippingMethods as $shippingMethod) {
                    $services = $shippingMethod->keendeliveryShippingMethodServices()->where('enabled', 1)->get();
                    foreach ($services as $service) {
                        foreach ($service->keendeliveryShippingMethodServiceOptions as $option) {
                            $data["shipping_method_service_option_$option->field"] = $option->default ?: null;
                        }
                    }
                }

                return $data;
            })
            ->form(function () {
                $shippingMethods = KeendeliveryShippingMethod::where('enabled', 1)->where('site_id', $this->order->site_id)->get();

                $schema = [];
                $schema[] = Select::make('shipping_method')
                    ->label('Kies een verzendmethode')
                    ->required()
                    ->reactive()
                    ->options($shippingMethods->pluck('name', 'value'));

                foreach ($shippingMethods as $shippingMethod) {
                    $services = $shippingMethod->keendeliveryShippingMethodServices()->where('enabled', 1)->get();
                    $schema[] = Select::make('service')
                        ->label('Kies een service')
                        ->required()
                        ->reactive()
                        ->options($services->pluck('name', 'value'))
                        ->hidden(fn (Get $get) => $get("shipping_method") != $shippingMethod->value);

                    foreach ($services as $service) {
                        foreach ($service->keendeliveryShippingMethodServiceOptions as $option) {
                            if ($option->type == 'textbox') {
                                $schema[] = TextInput::make("shipping_method_service_option_{$option->field}")
                                    ->label($option->name)
                                    ->maxLength(255)
                                    ->required($option->mandatory)
                                    ->hidden(fn (Get $get) => $get("service") != $service->value);
                            } elseif ($option->type == 'checkbox') {
                                $schema[] = Toggle::make("shipping_method_service_option_{$option->field}")
                                    ->label($option->name)
                                    ->required($option->mandatory)
                                    ->hidden(fn (Get $get) => $get("service") != $service->value);
                            } elseif ($option->type == 'email') {
                                $schema[] = TextInput::make("shipping_method_service_option_{$option->field}")
                                    ->type('email')
                                    ->label($option->name)
                                    ->required($option->mandatory)
                                    ->email()
                                    ->maxLength(255)
                                    ->hidden(fn (Get $get) => $get("service") != $service->value);
                            } elseif ($option->type == 'date') {
                                $schema[] = DatePicker::make("shipping_method_service_option_{$option->field}")
                                    ->label($option->name)
                                    ->required($option->mandatory)
                                    ->hidden(fn (Get $get) => $get("service") != $service->value);
                            } elseif ($option->type == 'selectbox') {
                                $choices = [];
                                foreach ($option->choices as $choice) {
                                    $choices[$choice['value']] = $choice['text'];
                                }
                                $schema[] = Select::make("shipping_method_service_option_{$option->field}")
                                    ->label($option->name)
                                    ->options($choices)
                                    ->required($option->mandatory)
                                    ->hidden(fn (Get $get) => $get("service") != $service->value);
                            } else {
                                dump('Contacteer Dashed om dit in te bouwen');
                            }
                        }
                    }
                }

                return $schema;
            })
            ->action(function ($data) {
                $this->validate();

                $response = KeenDelivery::createShipment($this->order, $data);
                if (isset($response['shipment_id'])) {
                    $keendeliveryOrder = new KeendeliveryOrder();
                    $keendeliveryOrder->order_id = $this->order->id;
                    $keendeliveryOrder->shipment_id = $response['shipment_id'];
                    $keendeliveryOrder->label = $response['label'];
                    Storage::disk('public')->put('/dashed/orders/keendelivery/labels/label-' . $this->order->invoice_id . '.pdf', base64_decode($response['label']));
                    $keendeliveryOrder->label_url = '/keendelivery/labels/label-' . $this->order->invoice_id . '.pdf';
                    $keendeliveryOrder->track_and_trace = $response['track_and_trace'];
                    $keendeliveryOrder->save();

                    $orderLog = new OrderLog();
                    $orderLog->order_id = $this->order->id;
                    $orderLog->user_id = Auth::user()->id;
                    $orderLog->tag = 'order.pushed-to-keendelivery';
                    $orderLog->save();

                    try {
                        Mail::to($this->order->email)->send(new TrackandTraceMail($keendeliveryOrder));

                        $orderLog = new OrderLog();
                        $orderLog->order_id = $this->order->id;
                        $orderLog->user_id = Auth::user()->id;
                        $orderLog->tag = 'order.t&t.send';
                        $orderLog->save();
                    } catch (\Exception $e) {
                        $orderLog = new OrderLog();
                        $orderLog->order_id = $this->order->id;
                        $orderLog->user_id = Auth::user()->id;
                        $orderLog->tag = 'order.t&t.not-send';
                        $orderLog->save();
                    }


                    $this->dispatch('refreshPage');
                    Notification::make()
                        ->title('De bestelling wordt binnen enkele minuten naar KeenDelivery gepushed.')
                        ->success()
                        ->send();
                } else {
                    foreach ($response as $error) {
                        if (is_array($error)) {
                            foreach ($error as $errorItem) {
                                Notification::make()
                                    ->title($errorItem)
                                    ->danger()
                                    ->send();
                            }
                        } else {
                            Notification::make()
                                ->title($error)
                                ->danger()
                                ->send();
                        }
                    }
                }
            });
    }
}
