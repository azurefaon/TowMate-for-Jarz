<form method="POST" action="{{ route('register') }}">
    @csrf

    <!-- NAME -->
    <input type="text" name="first_name" value="{{ old('first_name') }}" placeholder="First Name" required>
    @error('first_name')
        <small style="color:red">{{ $message }}</small>
    @enderror

    <input type="text" name="middle_name" value="{{ old('middle_name') }}" placeholder="Middle Name (optional)">
    @error('middle_name')
        <small style="color:red">{{ $message }}</small>
    @enderror

    <input type="text" name="last_name" value="{{ old('last_name') }}" placeholder="Last Name" required>
    @error('last_name')
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
