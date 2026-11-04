import Swal from 'sweetalert2';

const escapeHtml = (str) => {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
};

/**
 * SweetAlert2 공통 메시지 함수 — 확인 · 경고 · 입력창만
 *   화면 알림( Toast )은 SweetAlert 가 아니라 useToast().showFlash ( ToastHost — 여러 개 쌓임 · 확인창과 같이 떠도 사라지지 않음 )
 *   SweetAlert 는 팝업을 하나만 띄운다( 새 팝업이 앞의 것을 지운다 ) → toast 로 쓰면 쌓이지 않고 확인창과 서로 지운다
 * @param {string} title
 * @param {string} text
 * @param {string} icon - 'info' | 'success' | 'warning' | 'error' | 'question'
 * @param {string} type - 'alert' | 'confirm' | 'prompt' | 'prompt_password'
 * @param {object} options - SweetAlert2 추가 옵션
 * @param {string} className - 커스텀 클래스
 * @returns {Promise}
 */
export function showAlert(title = '', text = '', icon = 'info', type = 'alert', options = {}, className = '') {
    let settings = {
        title,
        text,
        icon,
        confirmButtonText: options.confirmButtonText ?? '확인',
        cancelButtonText: options.cancelButtonText ?? '취소',
        denyButtonText: '아니오',
        returnFocus: false,
        showClass: {
            popup: 'swal2 ' + className,
            backdrop: 'swal2-backdrop-show',
            icon: 'swal2-icon-show',
        },
        hideClass: {
            popup: 'swal2-hide',
            backdrop: 'swal2-backdrop-hide',
            icon: 'swal2-icon-hide',
        },
        customClass: {
            title: 'text-lg',
            htmlContainer: 'text-base',
            confirmButton: 'px-[14px] py-[6px]',
            cancelButton: 'px-[14px] py-[6px]'
        },
        buttonsStyling: true
    };

    if (type === 'confirm') {
        Object.assign(settings, {
            draggable: true,
            showCancelButton: true,
        });
    } else if (type === 'prompt') {
        Object.assign(settings, {
            showCancelButton: true,
            input: 'text',
        });
    } else if (type === 'prompt_password') {
        Object.assign(settings, {
            showCancelButton: true,
            input: 'password',
        });
    } else {
        Object.assign(settings, {
            timerProgressBar: false,
        });
    }

    if( /[\n]|<br>/.test( text ) ) {
        const tmp = '___BR___';
        let processed = text.replace(/\n/g, '<br>');
        processed = processed.replace(/<br\s*\/?>/gi, tmp);
        processed = escapeHtml(processed);
        processed = processed.replace(new RegExp(tmp, 'g'), '<br>');

        Object.assign(settings, {
            html: '<div class="format-preline">' + processed + '</div>',
        });
    }

    Object.assign(settings, options);

    return new Promise((resolve, reject) => {
        Swal.fire(settings).then(resolve).catch(reject);
    });
}