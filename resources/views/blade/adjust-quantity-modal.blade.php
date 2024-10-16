@props(['target','quantity'])
<div class="modal fade" id="adjust-quantity-modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                            aria-hidden="true">&times;</span>
                </button>
                <h2 class="modal-title">AdJuSt Qwuanteetee {{-- FIXME --}}</h2>
            </div>
            <div class="modal-body">
                <form id="adjust-quantity-form">
                    <input type="radio" name="direction" value="increase"/>Increase Inventory {{-- FIXME --}}
                    <input type="radio" name="direction" value="set"/>Set Inventory {{-- FIXME --}}
                    <input type="radio" name="direction" value="decrease"/>Decrease Inventory {{-- FIXME --}}<br/>
                    <input type="number" name="quantity" value="0"/>Amount {{-- FIXME --}} <br/>
                    {{ trans('general.order_number') }}: <input type="text" name="order_number"/><br/>
                    {{trans('general.purchase_date')}}<input type="date" name="order_date"/><br/>
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
        console.warn("Dollar is: "+$);
        console.warn("And target is: "+"{{ $target }}");
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
            console.warn("Direciton: "+form.direction.value+"Form note: "+form.note.value)
            if((form.direction.value == "decrease" && !form.note.value) || (form.direction.value == "set" && form.quantity.value < {{ $quantity }} && !form.note.value)) {
                window.alert("You can't decrease quantity without a note explaining why")
                return;
            }
            headers =

            $.post({
                url: "{{ $target }}",
                data: $('#adjust-quantity-form').serialize(),
                headers: {
                    "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr('content')
                }
            },function () {
                console.error("SUCCESS!!!!")
            }).done(function (error) {
                console.error("BOO, fail :(")
            })
        });
    </script>
    @endsection
</div>
