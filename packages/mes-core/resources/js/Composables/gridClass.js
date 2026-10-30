import { COLORS } from '@core/Utils/colors';
export class CheckboxRenderer {
    constructor( props ) {
        const { grid, rowKey } = props;

        const label = document.createElement('label');
        label.className = 'checkbox tui-grid-row-header-checkbox';
        label.setAttribute('for', String(rowKey));

        const hiddenInput = document.createElement('input');
        hiddenInput.className = 'hidden-input';
        hiddenInput.id = String(rowKey);

        const customInput = document.createElement('span');
        customInput.className = 'custom-input';

        label.appendChild(hiddenInput);
        label.appendChild(customInput);

        hiddenInput.type = 'checkbox';
        label.addEventListener('click', (ev) => {
            ev.preventDefault();

            if (ev.shiftKey) {
                grid[!hiddenInput.checked ? 'checkBetween' : 'uncheckBetween'](rowKey);
                return;
            }

            grid[!hiddenInput.checked ? 'check' : 'uncheck'](rowKey);
        });

        this.el = label;

        this.render(props);
    }

    getElement() {
        return this.el;
    }

    render(props) {
        const hiddenInput = this.el.querySelector('.hidden-input');
        const checked = Boolean(props.value);

        hiddenInput.checked = checked;
    }
}

// 토글 스위치 셀 ( core.css 의 .toggle-switch 스타일 사용 )
//   클릭 시 grid.setValue 로 값 전환 → 수정 행( getModifiedRows )에 포함됨
//   columns[].renderer = { type: ToggleRenderer, options: { onValue, offValue, onText, offText, disabled } }
//     disabled: boolean 또는 () => boolean ( 권한 등 런타임 값 )
export class ToggleRenderer {
    constructor( props ) {
        const { grid, rowKey, columnInfo } = props;
        const opts = columnInfo.renderer?.options ?? {};

        this.onValue  = opts.onValue  ?? 'Y';
        this.offValue = opts.offValue ?? 'N';
        this.onText   = opts.onText   ?? '';
        this.offText  = opts.offText  ?? '';
        this.isDisabled = () => ( typeof opts.disabled === 'function' ? opts.disabled() : !!opts.disabled );

        const wrap = document.createElement('label');
        wrap.style.cssText = 'display:inline-flex;align-items:center;gap:6px;user-select:none;vertical-align:middle;';

        const sw = document.createElement('span');
        sw.className = 'toggle-switch';

        this.input = document.createElement('input');
        this.input.type = 'checkbox';

        sw.appendChild(this.input);
        sw.appendChild(document.createElement('span'));

        wrap.appendChild(sw);

        this.textEl = document.createElement('span');

        if( this.onText != '' || this.offText != '' ) {
            this.textEl.style.cssText = 'font-size:12px;min-width:36px;text-align:left;';

            wrap.appendChild(this.textEl);
        };

        wrap.addEventListener('click', (ev) => {
            // label 기본 동작( input 체크 전환 )은 막고, 값은 그리드 데이터로만 바꾼다
            ev.preventDefault();
            if( this.isDisabled() ) return;

            const next = this.input.checked ? this.offValue : this.onValue;
            grid.setValue( rowKey, columnInfo.name, next );
        });

        this.el = wrap;

        this.render(props);
    }

    getElement() {
        return this.el;
    }

    render(props) {
        const on       = props.value === this.onValue;
        const disabled = this.isDisabled();

        this.input.checked  = on;
        this.input.disabled = disabled;
        this.el.style.cursor = disabled ? 'not-allowed' : 'pointer';

        this.textEl.textContent = on ? this.onText : this.offText;
        this.textEl.style.color = on ? COLORS.gray700 : COLORS.gray400;
    }
}

// 필수 컬럼 헤더 : 헤더명 + 빨간 * ( columns[].validation.required 인 컬럼에 useGrid 가 자동 적용 )
//   TUI Grid 기본 헤더는 텍스트로만 렌더되어 HTML 을 넣을 수 없으므로 렌더러로 처리
export class RequiredHeaderRenderer {
    constructor( props ) {
        const el = document.createElement('span');
        el.className = 'grid-required-header';

        this.textEl = document.createElement('span');

        const mark = document.createElement('span');
        mark.textContent = '*';
        mark.style.color = COLORS.red500;
        mark.style.marginLeft = '2px';

        el.appendChild(this.textEl);
        el.appendChild(mark);

        this.el = el;

        this.render(props);
    }

    getElement() {
        return this.el;
    }

    render(props) {
        this.textEl.textContent = props.columnInfo.header ?? '';
    }
}