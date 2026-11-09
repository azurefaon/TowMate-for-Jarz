@if (session('success'))
    <div class="fin-flash fin-flash--success" role="status">
        <i data-lucide="circle-check" aria-hidden="true"></i><span>{{ session('success') }}</span>
    </div>
@endif
@if (session('warning'))
    <div class="fin-flash fin-flash--warning" role="status" data-fin-warning>
        <i data-lucide="triangle-alert" aria-hidden="true"></i><span>{{ session('warning') }}</span>
    </div>
@endif
@if ($errors->any())
    <div class="fin-flash fin-flash--error" role="alert">
        <i data-lucide="circle-alert" aria-hidden="true"></i>
        <div>
            <strong>Nothing was saved.</strong>
            <ul>
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
