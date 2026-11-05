// 달력 날짜 도우미 — 날짜는 문자열 YYYY-MM-DD 로만 다룬다 ( 시간대 영향 없게 ) · MonthCalendar 와 그것을 쓰는 화면이 같이 쓴다
const pad = (n) => String(n).padStart(2, '0');

export const ymd      = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const parseYmd = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
export const addDays  = (s, n) => { const d = parseYmd(s); d.setDate(d.getDate() + n); return ymd(d); };
export const diffDays = (a, b) => Math.round((parseYmd(b) - parseYmd(a)) / 86400000);
export const todayYmd = () => ymd(new Date());

// 달 ( YYYY-MM )
export const thisMonth  = () => todayYmd().slice(0, 7);
export const monthLabel = (m) => `${m.slice(0, 4)}년 ${Number(m.slice(5))}월`;
export const shiftMonth = (m, n) => { const d = parseYmd(`${m}-01`); d.setMonth(d.getMonth() + n); return ymd(d).slice(0, 7); };

// 달력 범위 = 그 달 1일이 든 주의 일요일 ~ 마지막 날이 든 주의 토요일 ( 4 ~ 6주 — 다음 달만 있는 주는 없다 )
export function monthRange(m) {
    const first = parseYmd(`${m}-01`);
    const days  = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
    const weeks = Math.ceil((first.getDay() + days) / 7);
    const from  = addDays(ymd(first), -first.getDay());
    return { from, to: addDays(from, weeks * 7 - 1), weeks };
}
