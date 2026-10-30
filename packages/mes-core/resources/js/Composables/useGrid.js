import { ref, onUnmounted } from 'vue';
import Grid from 'tui-grid';
import 'tui-grid/dist/tui-grid.css';
import { CheckboxRenderer, RequiredHeaderRenderer } from '@core/Composables/gridClass';
import { COLORS } from '@core/Utils/colors';

export function useGrid() {
    const gridInstance = ref(null);
    let resizeObserver = null;

/**
 * 그리드를 초기화하고 DOM에 마운트하는 메인 함수
 * @param {ref} gridContainerRef - 그리드가 마운트될 DOM 엘리먼트의 ref / ex) <div ref="gridEl"></div>
 * @param {object} componentOptions - 컴포넌트에서 전달하는 특정 옵션
 */
    const setGrid = ( gridContainerRef, componentOptions ) => {
        if( !gridContainerRef.value ) {
                console.error( "Grid Container Not Found" );
            return;
        }

        const containerHeight = gridContainerRef.value.offsetHeight;
        const containerStyle  = getComputedStyle( gridContainerRef.value );

        let marginTop      = Number( containerStyle.marginTop.replace( "px", "" ) );
        let marginBottom   = Number( containerStyle.marginBottom.replace( "px", "" ) );
        let gridBodyHeight = containerHeight - ( marginTop + marginBottom ) - 21; // 21 : Grid Header Area Height

        // 모든 그리드에 공통적으로 적용될 기본 옵션을 정의
        const defaultOptions = {
            header: {
                height: 35
            },
            rowHeight: 33,
            minRowHeight: 33,
            bodyHeight: gridBodyHeight > 150 ? gridBodyHeight : 150,
            columnOptions: {
                resizable: true
            },
            useClientSort: false,
            scrollY: false,
            scrollX: false,
            showConfigButton: true
        };

        if( componentOptions?.rowHeaders == "checkbox" ) {
            let prefix = Math.random().toString(36).substring(2, 6);

            componentOptions.rowHeaders = [{
                type: "checkbox",
                header: `<label for="all-checkbox-${prefix}" class="checkbox">
                            <input type="checkbox" id="all-checkbox-${prefix}" class="hidden-input" name="_checked" />
                            <span class="custom-input"></span>
                         </label>`,
                renderer: {
                    type: CheckboxRenderer
                }
            }];
        };
        
        const finalOptions = {
            el: gridContainerRef.value, // 필수: 그리드가 그려질 실제 DOM 엘리먼트
            ...defaultOptions,
            ...componentOptions
        };

        // 필수 컬럼( validation.required ) 헤더에 빨간 * 표시
        //   컴포넌트가 header.columns 로 렌더러를 직접 지정한 컬럼은 건드리지 않음
        const headerColumns = [ ...( componentOptions?.header?.columns ?? [] ) ];
        ( finalOptions.columns ?? [] ).forEach( col => {
            if( col.validation?.required && !headerColumns.some( h => h.name === col.name ) ) {
                headerColumns.push( { name: col.name, renderer: RequiredHeaderRenderer } );
            };
        });
        finalOptions.header = {
            ...defaultOptions.header,
            ...componentOptions?.header,
            columns: headerColumns
        };

        Grid.setLanguage('ko'); // Grid 생성전 호출해야함

        const newGrid = new Grid( finalOptions );
        const borderColor = COLORS.gray300;
        
        Grid.applyTheme( 'clean', {
            grid: {
                border: borderColor
            },
            outline: {
                border: borderColor,
            },
            selection: {
                background: COLORS.blue400,
                border: COLORS.blue200
            },
            area: {
                border: borderColor
            },
            cell: {
                normal: {
                    border: borderColor,
                    showVerticalBorder: true,
                    showHorizontalBorder: true,
                    },
                header: {
                    background: COLORS.gray100,
                    border: borderColor,
                },
                rowHeader: {
                    border: borderColor,
                    showVerticalBorder: true,
                    showHorizontalBorder: true
                },
                selectedHeader: {
                    background: COLORS.gray200
                },
                focused: {
                    border: COLORS.blue500
                }
            }
        });

        if( finalOptions?.oneClickEdit == true ) {
            newGrid.on( "click", (ev) => {
                const target = ev.nativeEvent.target;
                let rowKey = ev.rowKey; 
                let columnName = ev.columnName;
                
                let col = newGrid.getColumn( columnName );

                if( rowKey != null && columnName != null ) {
                    let editor = col.editor;

                    if( editor != null ) {
                        newGrid.startEditing( rowKey, columnName );
                    };
                } else {
                    if( target.classList.contains( "tui-grid-body-area" ) || target.classList.contains( "tui-grid-layer-state" ) ) {
                        newGrid.blur();
                    };
                };
            });
        };

        newGrid.on( "onGridMounted", (ev) => {
            const headerArea = newGrid.el.querySelector( ".tui-grid-rside-area .tui-grid-header-area" );
            const bodyArea   = newGrid.el.querySelector( ".tui-grid-rside-area .tui-grid-body-area" );

            if( headerArea && bodyArea ) {
                headerArea.addEventListener( "wheel", (ev) => {
                    ev.preventDefault();

                    bodyArea.scrollLeft += ev.deltaY;
                });

                bodyArea.addEventListener( "wheel", (ev) => {
                    ev.stopImmediatePropagation();
                }, true );

                bodyArea.addEventListener( "wheel", (ev) => {
                    ev.preventDefault();

                    const bodyRect = bodyArea.getBoundingClientRect();

                    if( bodyRect.bottom <= ev.clientY ) { 
                        bodyArea.scrollLeft += ev.deltaY;
                    } else {
                        bodyArea.scrollTop += ev.deltaY;
                    };
                });
            };
        });

        gridInstance.value = newGrid;
    };

    const autoResizeGrid = ( gridContainerRef, grid ) => {
        const containerStyle  = getComputedStyle( gridContainerRef.value );

        let marginTop = Number( containerStyle.marginTop.replace( "px", "" ) );
        let marginBottom = Number( containerStyle.marginBottom.replace( "px", "" ) );

        resizeObserver = new ResizeObserver( vv => {
            for( let v of vv ) {
                const {height} = v.contentRect;

                if( grid != null && height > 0 ) {
                    grid.setHeight( height - ( marginTop + marginBottom ) );
                    grid.refreshLayout();
                };
            }
        });

        resizeObserver.observe( gridContainerRef.value );
    }
    
    onUnmounted(() => {
        if( gridInstance.value ) {
            gridInstance.value.destroy();
            gridInstance.value = null;
        };

        if( resizeObserver != null ) {
            resizeObserver.disconnect();
            resizeObserver = null;
        }
    });

    return {
        gridInstance,
        setGrid,
        autoResizeGrid
    };
}
