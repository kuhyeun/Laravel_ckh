// 스캔 입력 칸 ( 11-26 ) — 자재 출고 · 출하 · 상단 스캔 칸의 BaseInput 에 v-scan-input
//   한글 → 영문 : 브라우저는 입력 칸의 한 / 영 상태를 바꿀 수 없다 → 한글 입력 상태로 찍혀 들어온 글자를 키 자리 그대로 영문으로 되돌린다
//     ( ㅉㅒ-2611… → WO-2611… · 영문은 대문자로 — 바코드 번호는 대문자 · 서버 찾기는 대소문자를 가리지 않는다 )
//     조합이 끝날 때( compositionend )마다 바꾸고, 조합 중에 Enter 가 오면 조합이 끝난 뒤 바꿔서 Enter 를 다시 보낸다
//   화면 이동( 작업지시 · 공정 코드 )은 하지 않는다 — 그 칸의 원래 처리( 자재 · 출하품 )대로 ( 상단 스캔 칸 · 입력 칸 밖에서 찍을 때만 이동 )
//   글자 숨기기( 스캐너 입력 중 투명 · 한글 덮개 )는 되돌렸다 ( 11-26 — 손으로 칠 때도 투명해져 더 거슬렸다 )
//   쓰는 법 : import { vScanInput } from '@core/Composables/scanInput' → <BaseInput v-scan-input … />
const CHO  = ['ㄱ', 'ㄲ', 'ㄴ', 'ㄷ', 'ㄸ', 'ㄹ', 'ㅁ', 'ㅂ', 'ㅃ', 'ㅅ', 'ㅆ', 'ㅇ', 'ㅈ', 'ㅉ', 'ㅊ', 'ㅋ', 'ㅌ', 'ㅍ', 'ㅎ'];
const JUNG = ['ㅏ', 'ㅐ', 'ㅑ', 'ㅒ', 'ㅓ', 'ㅔ', 'ㅕ', 'ㅖ', 'ㅗ', 'ㅘ', 'ㅙ', 'ㅚ', 'ㅛ', 'ㅜ', 'ㅝ', 'ㅞ', 'ㅟ', 'ㅠ', 'ㅡ', 'ㅢ', 'ㅣ'];
const JONG = ['', 'ㄱ', 'ㄲ', 'ㄳ', 'ㄴ', 'ㄵ', 'ㄶ', 'ㄷ', 'ㄹ', 'ㄺ', 'ㄻ', 'ㄼ', 'ㄽ', 'ㄾ', 'ㄿ', 'ㅀ', 'ㅁ', 'ㅂ', 'ㅄ', 'ㅅ', 'ㅆ', 'ㅇ', 'ㅈ', 'ㅊ', 'ㅋ', 'ㅌ', 'ㅍ', 'ㅎ'];
// 두벌식 자판 자리
const KEY = {
    'ㄱ': 'r', 'ㄲ': 'R', 'ㄴ': 's', 'ㄷ': 'e', 'ㄸ': 'E', 'ㄹ': 'f', 'ㅁ': 'a', 'ㅂ': 'q', 'ㅃ': 'Q', 'ㅅ': 't', 'ㅆ': 'T', 'ㅇ': 'd', 'ㅈ': 'w', 'ㅉ': 'W',
    'ㅊ': 'c', 'ㅋ': 'z', 'ㅌ': 'x', 'ㅍ': 'v', 'ㅎ': 'g',
    'ㅏ': 'k', 'ㅐ': 'o', 'ㅑ': 'i', 'ㅒ': 'O', 'ㅓ': 'j', 'ㅔ': 'p', 'ㅕ': 'u', 'ㅖ': 'P', 'ㅗ': 'h', 'ㅘ': 'hk', 'ㅙ': 'ho', 'ㅚ': 'hl', 'ㅛ': 'y',
    'ㅜ': 'n', 'ㅝ': 'nj', 'ㅞ': 'np', 'ㅟ': 'nl', 'ㅠ': 'b', 'ㅡ': 'm', 'ㅢ': 'ml', 'ㅣ': 'l',
    'ㄳ': 'rt', 'ㄵ': 'sw', 'ㄶ': 'sg', 'ㄺ': 'fr', 'ㄻ': 'fa', 'ㄼ': 'fq', 'ㄽ': 'ft', 'ㄾ': 'fx', 'ㄿ': 'fv', 'ㅀ': 'fg', 'ㅄ': 'qt',
};
const HANGUL = /[ᄀ-ᇿ㄰-㆏가-힣]/;

// 한글 → 그 자판 자리의 영문 ( 대문자 )
export function hangulToKeys(text) {
    let out = '';
    for (const ch of String(text ?? '')) {
        const c = ch.charCodeAt(0);
        if (c >= 0xAC00 && c <= 0xD7A3) {
            const n = c - 0xAC00;
            out += (KEY[CHO[Math.floor(n / 588)]] ?? '') + (KEY[JUNG[Math.floor((n % 588) / 28)]] ?? '') + (KEY[JONG[n % 28]] ?? '');
        } else {
            out += KEY[ch] ?? ch;
        }
    }
    return out.toUpperCase();
}
export const hasHangul = (text) => HANGUL.test(String(text ?? ''));

export const vScanInput = {
    mounted(el) {
        const input = el.tagName === 'INPUT' ? el : el.querySelector('input');
        if (!input) return;
        let pendingEnter = false;
        const fix = () => {
            if (!hasHangul(input.value)) return;
            input.value = hangulToKeys(input.value);
            input.composing = false;   // Vue v-model 이 조합 중이면 무시한다
            input.dispatchEvent(new Event('input', { bubbles: true }));
        };
        const onCompEnd = () => setTimeout(() => {
            fix();
            if (pendingEnter) {
                pendingEnter = false;
                input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', bubbles: true, cancelable: true }));
            }
        }, 0);
        const onKey = (e) => {
            if (e.key !== 'Enter' && e.code !== 'Enter' && e.code !== 'NumpadEnter') return;
            if (e.isComposing || e.keyCode === 229) {   // 조합 중 Enter — 조합이 끝난 뒤 다시
                e.preventDefault(); e.stopImmediatePropagation();
                pendingEnter = true;
                return;
            }
            fix();
        };
        input.addEventListener('compositionend', onCompEnd);
        input.addEventListener('keydown', onKey, true);
        el._scanInput = { input, onCompEnd, onKey };
    },
    unmounted(el) {
        const s = el._scanInput;
        if (!s) return;
        s.input.removeEventListener('compositionend', s.onCompEnd);
        s.input.removeEventListener('keydown', s.onKey, true);
    },
};
