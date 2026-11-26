// 바코드 · QR 스캐너 전역 감지 ( 11-26 ) — 포커스를 뺏지 않는다
//   스캐너( 키보드 방식 )는 글자를 아주 빠르게( 글자 사이 50ms 안 ) 보낸 뒤 Enter → 그 묶음을 「스캔」으로 본다
//   사람이 천천히 치는 키 · 입력 칸( input · textarea · select · contenteditable )에서 친 키는 건드리지 않는다
//     ( 그리드의 복사용 textarea 는 입력 칸이 아니다 — 그리드를 누른 채로도 찍힌다 )
//   글자 : 보낸 글자가 영문 · 숫자 · 기호면 그대로 · 한글 입력 상태면 물리 키( event.code ) 자리로 바꿔 읽는다 → WO-… 가 그대로
//   처리 순서 : 화면이 올린 처리기( pushScanHandler — 위에서부터, true 를 돌려주면 끝 ) → 기본 = 서버에서 풀어 이동( production.scan.resolve )
//   TopFrame 의 스캔 칸( 손으로 넣기 )도 같은 dispatchScan 으로
import { reactive } from 'vue';
import { route } from 'ziggy-js';
import { router } from '@inertiajs/vue3';
import { useToast } from '@core/Composables/useToast';

const GAP_MS = 50;      // 글자 사이 최대 간격 ( 넘으면 새로 )
const MIN_LEN = 4;      // 이보다 짧으면 스캔으로 보지 않는다

const SHIFTED_DIGIT = { Digit1: '!', Digit2: '@', Digit3: '#', Digit4: '$', Digit5: '%', Digit6: '^', Digit7: '&', Digit8: '*', Digit9: '(', Digit0: ')' };
const PUNCT = {
    Minus: ['-', '_'], Equal: ['=', '+'], Slash: ['/', '?'], Period: ['.', '>'], Comma: [',', '<'], Semicolon: [';', ':'], Quote: ["'", '"'],
    BracketLeft: ['[', '{'], BracketRight: [']', '}'], Backslash: ['\\', '|'], Backquote: ['`', '~'], Space: [' ', ' '],
    NumpadSubtract: ['-', '-'], NumpadAdd: ['+', '+'], NumpadMultiply: ['*', '*'], NumpadDivide: ['/', '/'], NumpadDecimal: ['.', '.'],
};
// 키 → 글자 ( 모르는 키는 null ) — 보낸 글자가 영문 · 숫자 · 기호면 그대로, 아니면( 한글 입력 상태 · Process ) 물리 키 자리로
function charOf(e) {
    if (typeof e.key === 'string' && e.key.length === 1 && /[ -~]/.test(e.key)) return e.key;
    const c = e.code || '';
    const shift = e.shiftKey;
    if (/^Key[A-Z]$/.test(c)) {
        const caps = e.getModifierState?.('CapsLock');
        const up = shift !== !!caps;
        return up ? c.slice(3) : c.slice(3).toLowerCase();
    }
    if (/^Digit\d$/.test(c)) return shift ? SHIFTED_DIGIT[c] : c.slice(5);
    if (/^Numpad\d$/.test(c)) return c.slice(6);
    if (PUNCT[c]) return PUNCT[c][shift ? 1 : 0];
    return null;
}
const isField = (el) => !!el && el !== document.body
    && (el.matches?.('input, select, textarea, [contenteditable="true"], [contenteditable=""]') && !el.matches?.('.tui-grid-clipboard'));

export const scanState = reactive({ last: '', at: 0, busy: false, msg: '', error: false });
const handlers = [];

// 화면별 처리기 — fn( code ) 가 true( 또는 Promise<true> )면 기본 처리를 하지 않는다 · 돌려주는 함수로 떼기
export function pushScanHandler(fn) {
    handlers.push(fn);
    return () => { const i = handlers.indexOf(fn); if (i >= 0) handlers.splice(i, 1); };
}

// 결과 알림 = toast ( 11-26 — 스캔 칸 옆 글 대신 )
function say(msg, error = false) {
    try { useToast().showFlash(msg, error); } catch (e) { /* 앱 밖 */ }
}

export async function dispatchScan(raw) {
    const code = String(raw ?? '').trim();
    if (!code) return;
    scanState.last = code; scanState.at = Date.now();
    for (let i = handlers.length - 1; i >= 0; i--) {
        try { if (await handlers[i](code)) return; } catch (e) { /* 다음 처리기로 */ }
    }
    if (!route().has('production.scan.resolve')) { say(`${code} : 스캔 이동을 쓸 수 없습니다.`, true); return; }
    scanState.busy = true;
    try {
        const { data } = await window.axios.get(route('production.scan.resolve'), { params: { code } });
        if (!data.url) { say(data.message || `${code} : 알 수 없는 코드입니다.`, true); return; }
        say(`${data.type === 'OP' ? '공정' : data.type === 'SHEET' ? '작업지시서' : '작업지시'} ${data.label} 로 이동합니다.`);
        // 떠 있는 창은 닫고 이동 ( 같은 화면이어도 새로 그린다 — preserveState false )
        const { useModalStore } = await import('@core/Stores/modalStore');
        useModalStore().modals = [];
        router.visit(data.url, { preserveState: false });
    } catch (e) {
        say(`${code} : 확인하지 못했습니다.`, true);
    } finally {
        scanState.busy = false;
    }
}

// 전역 감지 — 앱에서 한 번 ( TopFrame )
let installed = false;
export function installScanner() {
    if (installed || typeof window === 'undefined') return;
    installed = true;
    let buf = '';
    let last = 0;
    let procCode = null;   // 한글 입력기가 「Process」로 넘긴 마지막 키 ( 같은 키가 곧바로 한 번 더 온다 )
    let procAt = 0;
    window.addEventListener('keydown', (e) => {
        if (e.ctrlKey || e.altKey || e.metaKey) { buf = ''; return; }
        if (isField(e.target)) { buf = ''; return; }
        if (/^(Shift|Control|Alt|Meta|CapsLock)/.test(e.code) || e.key === 'Shift') return;   // 글쇠 자체는 넘긴다
        const now = performance.now();
        if (now - last > GAP_MS) buf = '';
        last = now;
        // 한글 입력기 ( 11-26 ) : 조합 중( W · O 등 )에 다른 키가 오면 「Process」 한 번 + 그 키 한 번, 같은 키가 두 번 온다 → 뒤의 것은 건너뛴다
        //   ( WO-… 가 WO--… 로 읽혔다 · 스캐너가 진짜로 -- 를 보내면 둘 다 Process 가 아니라 그대로 )
        if (e.key === 'Process' || e.keyCode === 229) {
            procCode = e.code; procAt = now;
        } else if (procCode && e.code === procCode && now - procAt <= GAP_MS) {
            procCode = null;
            return;
        } else {
            procCode = null;
        }
        if (e.code === 'Enter' || e.code === 'NumpadEnter') {
            if (buf.length >= MIN_LEN) {
                e.preventDefault(); e.stopPropagation();
                const code = buf; buf = '';
                dispatchScan(code);
            }
            buf = '';
            return;
        }
        const ch = charOf(e);
        if (ch === null) { buf = ''; return; }
        buf += ch;
    }, true);
}
