// SheetJS( xlsx ) 빈 대체 — package.json 의 overrides 로 tui-grid 의 의존성을 이것으로 바꾼다
//   이유 : tui-grid 4.21 이 xlsx 0.17.5 ( 무료판, 알려진 취약점 Prototype Pollution · ReDoS )를 끌고 와 npm audit 에 high 로 남는다
//   tui-grid 는 xlsx 를 그리드 기본 엑셀 내보내기( 오른쪽 클릭 메뉴 )에만 쓰는데, 그 메뉴는 DataGrid 에서 뺐다
//   XLSX.writeFile 이 없으면 tui-grid 는 엑셀 내보내기만 건너뛰고 콘솔에 안내를 남긴다 ( 그리드 자체는 정상 )
//   엑셀 내보내기 · 업로드는 exceljs 로 한다 ( Utils/excel.js · Utils/excelImport.js )
module.exports = {};
