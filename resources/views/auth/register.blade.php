<form method="POST" action="{{ route('register') }}">
    @csrf

    <!-- NAME -->
    <input type="text" name="name" value="{{ old('name') }}" placeholder="Your Name" required>
    @error('name')
        <small style="color:red">{{ $message }}</small>
    @enderror

    <!-- EMAIL -->
    <input type="email" name="email" value="{{ old('email') }}" placeholder="Email" required>
    @error('email')
        <small style="color:red">{{ $message }}</small>
    @enderror

    <!-- PASSWORD -->
    <input type="password" name="password" id="registerPassword" required>
    @error('password')
        <small style="color:red">{{ $message }}</small>
    @enderror

    <!-- CONFIRM -->
    <input type="password" name="password_confirmation" id="confirmPassword" required>

    <button type="submit" class="register-btn">
        Create Account
    </button>
</form>
