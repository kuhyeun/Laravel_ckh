<?php

namespace MesCore\Auth\Observers;

use MesCore\Auth\Models\User;
use Illuminate\Support\Facades\Auth;

class UserObserver {

    /**
     * INSERT 실행 전 시점에 실행
     * return false 를 하는 경우 저장이 취소됨 ( 리턴 형식 bool 또는 void 제거 )
     */
    public function creating( User $user ): void {
        if( Auth::check() ) {
            $user->create_account_idx = Auth::id();
        }
    }

    /**
     * INSERT 완료 후에 실행
     */
    public function created( User $user ): void {

    }

    public function updating( User $user ): void {
        if( Auth::check() ) {
            $user->update_account_idx = Auth::id();
        }
    }

    public function updated( User $user ): void {

    }
}
