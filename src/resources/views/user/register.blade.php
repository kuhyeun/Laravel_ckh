<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>회원가입</title>
    <style>
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; flex-direction: column; }
        form { border: 1px solid #ccc; padding: 2rem; border-radius: 8px; }
        div { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.25rem; }
        input { padding: 0.5rem; width: 250px; }
        button { padding: 0.5rem 1rem; cursor: pointer; }
        .error { color: red; font-size: 0.875rem; margin-top: 0.25rem; }
        .link { margin-top: 1rem; }
    </style>
</head>
<body>
    <h1>회원가입</h1>

    <form method="POST" action="{{ route('user.register.process') }}">
        @csrf

        <div>
            <label for="user_name">사용자 이름</label>
            <input id="user_name" type="text" name="user_name" value="{{ old('user_name') }}" required autofocus>
            
            @error('user_name')
                <p class="error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="user_id">아이디</label>
            <input id="user_id" type="text" name="user_id" value="{{ old('user_id') }}" required autofocus>
            
            {{-- 유효성 검사 에러 (ID 중복 등) --}}
            @error('user_id')
                <p class="error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password">비밀번호</label>
            <input id="password" type="password" name="password" required>

            {{-- 유효성 검사 에러 (8자 미만, 비밀번호 확인 불일치 등) --}}
            @error('password')
                <p class="error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation">비밀번호 확인</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required>
        </div>

        <div>
            <button type="submit">가입하기</button>
        </div>
    </form>
    
    <div class="link">
        <a href="{{ route('user.login') }}">이미 계정이 있으신가요? 로그인</a>
    </div>
</body>
</html>
