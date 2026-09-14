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

// 필수 컬럼 헤더 : 헤더명 + 빨간 * ( columns[].validation.required 인 컬럼에 useGrid 가 자동 적용 )
//   TUI Grid 기본 헤더는 텍스트로만 렌더되어 HTML 을 넣을 수 없으므로 렌더러로 처리
export class RequiredHeaderRenderer {
    constructor( props ) {
        const el = document.createElement('span');
        el.className = 'grid-required-header';

        this.textEl = document.createElement('span');

        const mark = document.createElement('span');
        mark.textContent = '*';
        mark.style.color = '#ef4444';
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