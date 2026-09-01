import Swal from 'sweetalert2';

const escapeHtml = (str) => {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
};

/**
 * SweetAlert2 공통 메시지 함수
 * @param {string} title
 * @param {string} text
 * @param {string} icon - 'info' | 'success' | 'warning' | 'error' | 'question'
 * @param {string} type - 'alert' | 'confirm' | 'prompt' | 'prompt_password' | 'toast'
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
            confirmButton: 'basic-btn h-[40px]',
            cancelButton: 'basic-btn'
        },
        buttonsStyling: false
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
    } else if (type === 'toast') {
        Object.assign(settings, {
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 30000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener( "click", function(ev){
                    Swal.close();
                });

                toast.style.cursor = 'pointer';
            }
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