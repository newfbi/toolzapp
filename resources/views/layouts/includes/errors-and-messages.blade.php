<div>
    @if ($errors->all())
        @foreach ($errors->all() as $message)
            <div class="alert alert-warning fade-out" role="alert">
                <i class="fa fa-exclamation-circle alert-icon mr-3"></i>
                <span class="alert-link">{{ $message }}</span>
            </div>
        @endforeach
    @elseif($message = Session::get('message'))
        <div class="alert alert-success fade-out" role="alert">
            <i class="fa fa-thumbs-up alert-icon mr-3"></i>
            <span>{{ $message }}</span>
        </div>
    @elseif($message = Session::get('success'))
        <div class="alert alert-success fade-out" role="alert">
            <i class="fa fa-thumbs-up alert-icon mr-3"></i>
            <span>{{ $message }}</span>
        </div>
    @elseif($message = Session::get('warning'))
        <div class="alert alert-warning fade-out" role="alert">
            <i class="fa fa-thumbs-down alert-icon mr-3"></i>
            <span>{{ $message }}</span>
        </div>
    @elseif($message = Session::get('error'))
        <div class="alert alert-danger fade-out" role="alert">
            <i class="fa fa-minus-circle alert-icon mr-3"></i>
            <span>{{ $message }}</span>
        </div>
    @endif
</div>
