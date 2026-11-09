// 마지막 요청만 반영 — 빠르게 다른 행 · 탭 · 지시를 고를 때 늦게 온 앞 응답이 화면을 덮지 않게 ( 11-09 코드 검사 )
//   const latest = useLatest();
//   async function load() {
//       const isLatest = latest.begin();          // 이 요청 표 — 다음 begin() 이 오면 낡은 것
//       loading.value = true;
//       try {
//           const { data } = await axios.get(...);
//           if (!isLatest()) return;               // 그 사이 다른 것을 골랐다 → 버린다
//           rows.value = data.rows;
//       } finally {
//           if (isLatest()) loading.value = false; // 앞 요청이 먼저 끝나도 로딩을 끄지 않는다
//       }
//   }
//   latest.cancel() : 진행 중인 요청을 모두 낡은 것으로 ( 화면을 비울 때 )
export function useLatest() {
    let seq = 0;
    return {
        begin() {
            const my = ++seq;
            return () => my === seq;
        },
        cancel() {
            seq++;
        },
    };
}
