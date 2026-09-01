<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>로그인</title>
    <style>
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; flex-direction: column; }
        form { border: 1px solid #ccc; padding: 2rem; border-radius: 8px; }
        div { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.25rem; }
        input { padding: 0.5rem; width: 250px; }
        button { padding: 0.5rem 1rem; cursor: pointer; }
        .error { color: red; font-size: 0.875rem; margin-top: 0.25rem; }
        .success { color: green; border: 1px solid green; padding: 1rem; margin-bottom: 1rem; border-radius: 8px; }
        .link { margin-top: 1rem; }
    </style>
</head>
<body>
    <h1>로그인</h1>

    @error('error')
        <div class="error" style="border: 1px solid red; padding: 1rem; margin-bottom: 1rem; border-radius: 8px;">
            {{ $message }}
        </div>
    @enderror

    {{-- 회원가입 성공 시 메시지 --}}
    @if(session('success'))
        <div class="success">
            {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('user.login.process') }}">
        @csrf

        <div>
            <label for="user_id">아이디</label>
            <input id="user_id" type="text" name="user_id" value="{{ old('user_id') }}" required autofocus>
            
            {{-- 아이디 또는 비밀번호가 틀렸을 경우 에러 메시지 --}}
            @error('user_id')
                <p class="error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password">비밀번호</label>
            <input id="password" type="password" name="password" required>
        </div>

        <div>
            <button type="submit">로그인</button>
        </div>
    </form>

    <div class="link" style="display: none;">
        <a href="{{ route('user.register') }}">아직 회원이 아니신가요? 회원가입</a>
    </div>
</body>
</html>
