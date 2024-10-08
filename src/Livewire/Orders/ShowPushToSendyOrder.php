<?php

namespace Dashed\DashedEcommerceSendy\Livewire\Orders;

use Filament\Forms\Get;
use Livewire\Component;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Support\Facades\Storage;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\Contracts\HasActions;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceSendy\Classes\Sendy;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Filament\Forms\Concerns\InteractsWithForms;
use Dashed\DashedEcommerceSendy\Models\SendyOrder;
use Filament\Actions\Concerns\InteractsWithActions;
use Dashed\DashedEcommerceSendy\Mail\TrackandTraceMail;
use Dashed\DashedEcommerceSendy\Models\SendyShippingMethod;

class ShowPushToSendyOrder extends Component implements HasForms, HasActions
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
            ->label('Verstuur naar Sendy')
            ->color('primary')
            ->fillForm(function () {
                $data = [];

                $shippingMethods = SendyShippingMethod::where('enabled', 1)->where('site_id', $this->order->site_id)->get();
                foreach ($shippingMethods as $shippingMethod) {
                    $services = $shippingMethod->sendyShippingMethodServices()->where('enabled', 1)->get();
                    foreach ($services as $service) {
                        foreach ($service->sendyShippingMethodServiceOptions as $option) {
                            $data["shipping_method_service_{$service->id}_option_$option->field"] = $option->default ?: null;
                        }
                    }
                }

                return $data;
            })
            ->form(function () {
                $shippingMethods = SendyShippingMethod::where('enabled', 1)->where('site_id', $this->order->site_id)->get();

                $schema = [];
                $schema[] = Select::make('shipping_method')
                    ->label('Kies een verzendmethode')
                    ->required()
                    ->reactive()
                    ->options($shippingMethods->pluck('name', 'value'));

                foreach ($shippingMethods as $shippingMethod) {
                    $services = $shippingMethod->sendyShippingMethodServices()->where('enabled', 1)->get();
                    $schema[] = Select::make('service')
                        ->label('Kies een service')
                        ->required()
                        ->reactive()
                        ->options($services->pluck('name', 'value'))
                        ->hidden(fn (Get $get) => $get("shipping_method") != $shippingMethod->value);

                    foreach ($services as $service) {
                        foreach ($service->sendyShippingMethodServiceOptions as $option) {
                            if ($option->type == 'textbox') {
                                $schema[] = TextInput::make("shipping_method_service_{$service->id}_option_{$option->field}")
                                    ->label($option->name)
                                    ->maxLength(255)
                                    ->required($option->mandatory)
                                    ->hidden(fn (Get $get) => $get("service") != $service->value);
                            } elseif ($option->type == 'checkbox') {
                                $schema[] = Toggle::make("shipping_method_service_{$service->id}_option_{$option->field}")
                                    ->label($option->name)
                                    ->required($option->mandatory)
                                    ->hidden(fn (Get $get) => $get("service") != $service->value);
                            } elseif ($option->type == 'email') {
                                $schema[] = TextInput::make("shipping_method_service_{$service->id}_option_{$option->field}")
                                    ->type('email')
                                    ->label($option->name)
                                    ->required($option->mandatory)
                                    ->email()
                                    ->maxLength(255)
                                    ->hidden(fn (Get $get) => $get("service") != $service->value);
                            } elseif ($option->type == 'date') {
                                $schema[] = DatePicker::make("shipping_method_service_{$service->id}_option_{$option->field}")
                                    ->label($option->name)
                                    ->required($option->mandatory)
                                    ->hidden(fn (Get $get) => $get("service") != $service->value);
                            } elseif ($option->type == 'selectbox') {
                                $choices = [];
                                foreach ($option->choices as $choice) {
                                    $choices[$choice['value']] = $choice['text'];
                                }
                                $schema[] = Select::make("shipping_method_service_{$service->id}_option_{$option->field}")
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

                $response = Sendy::createShipment($this->order, $data);
                if (isset($response['shipment_id'])) {
                    $sendyOrder = new SendyOrder();
                    $sendyOrder->order_id = $this->order->id;
                    $sendyOrder->shipment_id = $response['shipment_id'];
                    $sendyOrder->label = $response['label'];
                    $time = uniqid();
                    Storage::disk('public')->put('/dashed/orders/sendy/labels/label-' . $this->order->invoice_id . '-' . $time . '.pdf', base64_decode($response['label']));
                    $sendyOrder->label_url = '/sendy/labels/label-' . $this->order->invoice_id . '-' . $time . '.pdf';
                    $sendyOrder->track_and_trace = $response['track_and_trace'];
                    $sendyOrder->save();

                    foreach ($response['track_and_trace'] as $code => $link) {
                        $this->order->addTrackAndTrace('sendy', $data['service'], $code, $link);
                    }

                    $orderLog = new OrderLog();
                    $orderLog->order_id = $this->order->id;
                    $orderLog->user_id = Auth::user()->id;
                    $orderLog->tag = 'order.pushed-to-sendy';
                    $orderLog->save();

                    //                    try {
                    //                        Mail::to($this->order->email)->send(new TrackandTraceMail($sendyOrder));
                    //
                    //                        $orderLog = new OrderLog();
                    //                        $orderLog->order_id = $this->order->id;
                    //                        $orderLog->user_id = Auth::user()->id;
                    //                        $orderLog->tag = 'order.t&t.send';
                    //                        $orderLog->save();
                    //                    } catch (\Exception $e) {
                    //                        $orderLog = new OrderLog();
                    //                        $orderLog->order_id = $this->order->id;
                    //                        $orderLog->user_id = Auth::user()->id;
                    //                        $orderLog->tag = 'order.t&t.not-send';
                    //                        $orderLog->save();
                    //                    }


                    $this->dispatch('refreshPage');
                    Notification::make()
                        ->title('De bestelling is naar Sendy gepushed.')
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
