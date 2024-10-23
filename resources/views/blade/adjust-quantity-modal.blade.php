@props(['target','quantity' => 0])
<div class="modal fade" id="adjust-quantity-modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                            aria-hidden="true">&times;</span>
                </button>
                <h2 class="modal-title">{{ trans('general.adjust_quantity') }}</h2>
            </div>
            <div class="modal-body">
                <form id="adjust-quantity-form">
                    <input class="direction-radio" type="radio" name="direction"
                           value="increase"/>{{ trans('general.increase_inventory') }}
                    <input class="direction-radio" type="radio" name="direction"
                           value="set"/>{{ trans('general.set_inventory') }}
                    <input class="direction-radio" type="radio" name="direction"
                           value="decrease"/>{{ trans('general.decrease_inventory') }}<br/>
                    <input class="direction-radio" type="number" name="quantity"
                           value="0"/>{{ trans('general.inventory_amount') }}<br/>
                    <hr>
                    @include('partials/forms/edit/supplier-select',['fieldname' => 'supplier_id','translated_name' => trans('general.supplier'),'hide_new' => true /* TODO - shoudl we allow new suppliers here? */])
                    <br/><br/>
                    {{ trans('general.order_number') }}: <input type="text" name="order_number"/><br/>
                    {{trans('general.purchase_date')}}<input type="date" name="purchase_date"/><br/>
                    {{ trans('general.purchase_cost') }}<input type="number" name="purchase_cost" step="0.01"/><br/>

                    Notes:<br/> <textarea name="note"></textarea>
                </form>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default pull-left"
                        data-dismiss="modal">{{ trans('button.cancel') }}</button>
                <button type="button" class="btn btn-primary pull-right"
                        id="adjust-quantity-save">{{ trans('general.update') }}</button>
            </div>
        </div>
    </div>
    @section('moar_scripts')
    <script>
        {{--console.warn("Dollar is: "+$);--}}
        {{--console.warn("And target is: "+"{{ $target }}");--}}
        $('#adjust-quantity-save').on('click',function (event) {
            // window.alert("HI THERE!");
            var form = document.forms['adjust-quantity-form'];
            if(! form.direction.value) {
                window.alert('pick a direction')
                return;
            }
            if(form.direction.value != "set" && form.quantity.value == 0) {
                window.alert("Can't adjust inventory up or down by 0");
                return;
            }
            //FIXME - what if the quantity shrank out from under you?!
            // Well, then, we handle that on the server-side, dipshit.
            console.warn("Direciton: "+form.direction.value+"Form note: "+form.note.value)
            if((form.direction.value == "decrease" && !form.note.value) || (form.direction.value == "set" && form.quantity.value < {{ $quantity }} && !form.note.value)) {
                window.alert("You can't decrease quantity without a note explaining why")
                return;
            }
            if (form.direction.value == "decrease" && {{ $quantity }} - form.quantity.value < 0) {
                window.alert("Cannot have negative quantity");
                return;
            }

            $.post({
                url: "{{ $target }}",
                data: $('#adjust-quantity-form').serialize(),
                headers: {
                    "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr('content')
                }
            }).done(function (thing) {
                //TODO: can we 'flash' something here? I don't know.
                //TODO - maybe we set the _flash_ in the controller, and do a redirect *here*
                //TODO - which can force the new value to show up, *AND* display the flash?
                $('#adjust-quantity-modal').modal('hide');
                form.note.value = '';
                $('.direction-radio').prop('checked', false);
                form.quantity.value = 0;
                form.order_number.value = '';
                form.purchase_date.value = '';
                form.purchase_cost.value = '';
                $(form.supplier_id).select2('val', 0);
            }).fail(function (error) {
                console.error("FAILTOWN: ")
                console.error(error)
            });
        });
    </script>
    @endsection
</div>
