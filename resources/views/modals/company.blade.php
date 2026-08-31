{{-- See snipeit_modals.js for what powers this --}}
<div class="modal-dialog">
    <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
            <h2 class="modal-title">{{ trans('admin/companies/table.create') }}</h2>
        </div>
        <div class="modal-body">
            <form action="{{ route('api.companies.store') }}" onsubmit="return false">
                <x-alert type="danger" id="modal_error_msg" style="display:none">
                </x-alert>
                @include('modals.partials.name', ['required' => 'true'])
            </form>
        </div>
        @include('modals.partials.footer')
    </div>
</div>
