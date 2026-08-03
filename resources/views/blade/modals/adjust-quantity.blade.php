<div class="modal fade" id="adjustQuantityModal" tabindex="-1" role="dialog" aria-labelledby="adjustQuantityModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ trans('button.close') }}">
                    <span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="adjustQuantityModalLabel">
                    {{ trans('general.adjust_quantity') }}
                    <small class="adjust-quantity-item-name text-muted"></small>
                </h4>
            </div>
            <form id="adjustQuantityForm" method="POST" action="" enctype="multipart/form-data">
                @csrf
                <div class="modal-body">
                    <p>
                        {{ trans('general.available') }}:
                        <strong class="adjust-quantity-available"></strong>
                    </p>

                    <div class="form-group">
                        <label for="adjustQuantityAmount">{{ trans('general.adjust_quantity_amount') }}</label>
                        {{-- min is populated by snipeit.js when the modal opens (–available). The
                             browser stepper then refuses to go below and the built-in
                             constraint-validation message surfaces if a user types past it. --}}
                        <input type="number" class="form-control" id="adjustQuantityAmount" name="amount" step="1" data-rule-notzero="true" required>
                        <p class="help-block">{{ trans('general.adjust_quantity_amount_help') }}</p>
                    </div>

                    <div class="form-group">
                        <label for="adjustQuantityOrder">{{ trans('general.order_number') }}</label>
                        <input type="text" class="form-control" id="adjustQuantityOrder" name="order_number" maxlength="191">
                    </div>

                    <div class="form-group">
                        <label for="adjustQuantityNote">{{ trans('general.notes') }}</label>
                        <textarea class="form-control" id="adjustQuantityNote" name="note" rows="3" maxlength="65535" required></textarea>
                    </div>

                    {{-- Inlined instead of <x-input.file-upload> because that
                         component wraps its markup in <x-form.row>, a Bootstrap 3
                         horizontal-form scaffold (col-md-3 label + col-md-9
                         input). The three fields above use vertical form-group
                         styling, so the horizontal scaffold collided with the
                         modal-footer top border. --}}
                    <div class="form-group">
                        <label for="adjustQuantityFile">{{ trans('general.file_upload') }}</label>
                        <div>
                            <label class="btn btn-sm btn-theme" for="adjustQuantityFile">
                                {{ trans('button.select_file') }}
                                <input
                                    type="file"
                                    name="file"
                                    class="js-uploadFile"
                                    id="adjustQuantityFile"
                                    data-maxsize="{{ \App\Helpers\Helper::file_upload_max_size() }}"
                                    accept="{{ config('filesystems.allowed_upload_mimetypes') }}"
                                    style="display:none"
                                    aria-label="file"
                                    aria-hidden="true"
                                >
                            </label>
                            <span id="adjustQuantityFile-info"></span>
                            <p class="help-block" id="adjustQuantityFile-status">
                                {{ trans('general.upload_filetypes_help', ['allowed_filetypes' => config('filesystems.allowed_upload_extensions'), 'size' => \App\Helpers\Helper::file_upload_max_size_readable()]) }}
                            </p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default pull-left" data-dismiss="modal">{{ trans('button.cancel') }}</button>
                    <button type="submit" class="btn btn-primary pull-right">{{ trans('general.save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
