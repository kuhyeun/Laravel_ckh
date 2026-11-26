// 스캔 입력 칸 ( 11-26 ) — 자재 출고 · 출하 · 상단 스캔 칸의 BaseInput 에 v-scan-input
//   1 ) 한글 → 영문 : 브라우저는 입력 칸의 한 / 영 상태를 바꿀 수 없다 → 한글 입력 상태로 찍혀 들어온 글자를 키 자리 그대로 영문으로 되돌린다
//        ( ㅉㅒ-2611… → WO-2611… · 영문은 대문자로 — 바코드 번호는 대문자 · 서버 찾기는 대소문자를 가리지 않는다 )
//        조합이 끝날 때( compositionend )마다 바꾸고, 조합 중에 Enter 가 오면 조합이 끝난 뒤 바꿔서 Enter 를 다시 보낸다
//   화면 이동( 작업지시 · 공정 코드 )은 하지 않는다 — 그 칸의 원래 처리( 자재 · 출하품 )대로 ( 11-26 · 상단 스캔 칸 · 입력 칸 밖에서 찍을 때만 이동 )
//   2 ) 스캐너가 넣는 동안 글자를 숨긴다 ( 11-26 ) — 글자 사이가 아주 짧은( 40ms 안 ) 연속 입력이면 그 칸 글자를 투명하게
//        ( 한글 조합 글자가 샥 생겼다 바뀌고 지워지는 게 어색했다 · 조합 중인 글자는 입력기가 그려 웹에서 영문으로 바꿔 보일 수 없다 )
//        마지막 키에서 0.3초 뒤 · Enter 처리 뒤 다시 보인다 · 사람이 치는 글자는 그대로 보인다
//   3 ) 한글이 들어 있는 동안( 조합 중 포함 )은 진짜 글자를 숨기고 그 위에 영문으로 바꾼 글자를 덮어 그린다 ( 11-26 )
//        → 손으로 치든 스캐너든 처음부터 영문만 보인다 · 조합이 끝나면 값 자체가 영문으로 바뀌어 덮개는 사라진다
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

// 숨김 모양 ( 한 번만 )
let styled = false;
function ensureStyle() {
    if (styled || typeof document === 'undefined') return;
    styled = true;
    const st = document.createElement('style');
    st.textContent = '.scan-burst,.scan-hangul{color:transparent!important;caret-color:transparent!important;text-shadow:none!important}'
        + '.scan-overlay{position:fixed;z-index:9999;pointer-events:none;white-space:pre;overflow:hidden;display:none;box-sizing:border-box}';
    document.head.appendChild(st);
}
const BURST_MS = 60;   // 글자 사이가 이보다 짧으면 스캐너 ( 사람은 보통 80ms 넘는다 )

export const vScanInput = {
    mounted(el) {
        ensureStyle();
        const input = el.tagName === 'INPUT' ? el : el.querySelector('input');
        if (!input) return;
        let pendingEnter = false;
        // 덮개 : 한글이 들어 있는 동안 영문으로 바꾼 글자를 그 칸 위에
        const overlay = document.createElement('div');
        overlay.className = 'scan-overlay';
        document.body.appendChild(overlay);
        let raf = 0;
        const paint = () => {
            raf = 0;
            const v = input.value;
            const on = hasHangul(v) && document.activeElement === input;
            input.classList.toggle('scan-hangul', on);
            if (!on || input.classList.contains('scan-burst')) { overlay.style.display = 'none'; return; }
            const r = input.getBoundingClientRect();
            const cs = getComputedStyle(input);
            Object.assign(overlay.style, {
                display: 'block', left: `${r.left}px`, top: `${r.top}px`, width: `${r.width}px`, height: `${r.height}px`, lineHeight: `${r.height}px`,
                paddingLeft: cs.paddingLeft, paddingRight: cs.paddingRight, font: cs.font, letterSpacing: cs.letterSpacing,
                textAlign: cs.textAlign, color: '#374151',
            });
            overlay.textContent = hangulToKeys(v);
        };
        const repaint = () => { if (!raf) raf = requestAnimationFrame(paint); };
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
        // 스캐너 입력 감지 → 글자 숨김
        let lastKey = 0;
        let showTimer = null;
        const show = (ms) => { clearTimeout(showTimer); showTimer = setTimeout(() => { input.classList.remove('scan-burst'); repaint(); }, ms); };
        const onKey = (e) => {
            const isEnter = e.key === 'Enter' || e.code === 'Enter' || e.code === 'NumpadEnter';
            if (!isEnter && (e.key?.length === 1 || e.key === 'Process' || e.keyCode === 229) && !e.ctrlKey && !e.altKey && !e.metaKey) {
                const now = performance.now();
                if (now - lastKey <= BURST_MS) input.classList.add('scan-burst');
                lastKey = now;
                if (input.classList.contains('scan-burst')) show(300);
            }
            if (isEnter && input.classList.contains('scan-burst')) show(120);   // 처리( 칸 비우기 )가 끝난 뒤 보이게
            if (!isEnter) return;
            if (e.isComposing || e.keyCode === 229) {   // 조합 중 Enter — 조합이 끝난 뒤 다시
                e.preventDefault(); e.stopImmediatePropagation();
                pendingEnter = true;
                return;
            }
            fix();
        };
        input.addEventListener('compositionend', onCompEnd);
        input.addEventListener('keydown', onKey, true);
        const evs = ['input', 'compositionstart', 'compositionupdate', 'compositionend', 'keyup', 'focus', 'blur'];
        evs.forEach(n => input.addEventListener(n, repaint));
        window.addEventListener('scroll', repaint, true);
        window.addEventListener('resize', repaint);
        el._scanInput = { input, onCompEnd, onKey, stop: () => {
            clearTimeout(showTimer); cancelAnimationFrame(raf);
            evs.forEach(n => input.removeEventListener(n, repaint));
            window.removeEventListener('scroll', repaint, true);
            window.removeEventListener('resize', repaint);
            overlay.remove();
        } };
    },
    unmounted(el) {
        const s = el._scanInput;
        if (!s) return;
        s.input.removeEventListener('compositionend', s.onCompEnd);
        s.input.removeEventListener('keydown', s.onKey, true);
        s.stop();
    },
};
