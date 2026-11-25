// 도장 ( 11-25 ) — 문서 양식 · 라벨 양식의 design.stamps 를 만드는 쪽( StampPanel )과 그리는 쪽( StampLayer )이 같이 쓴다
//   stamp : { id, src, x_mm, y_mm, w_mm, h_mm } — mm 는 상자( 문서 = 여백 포함 A4 장 / 라벨 = 라벨 ) 왼쪽 위 기준
//   src   : 'site:seal' · 'site:logo' ( 자사정보관리의 직인 · 로고 ) 또는 올린 그림의 data URI ( PNG · JPEG )
//   서버 검사 : Support/StampDesign ( 개수 · 그림 종류 · 크기 · 상자 안 )

export const MAX_STAMPS = 10;
export const MAX_BYTES  = 400 * 1024;   // 올린 그림 하나 ( 서버 StampDesign::MAX_BYTES 와 같게 )
const MAX_PX = 600;                      // 긴 변을 이만큼으로 줄여 저장한다

// 그릴 그림 주소 ( 자사 그림이 없으면 null )
export function stampSrc(s, images) {
    if (s.src === 'site:seal') return images?.seal ?? null;
    if (s.src === 'site:logo') return images?.logo ?? null;
    return s.src;
}

const loadImage = (src) => new Promise((resolve, reject) => {
    const img = new Image();
    img.onload  = () => resolve(img);
    img.onerror = () => reject(new Error('그림을 읽지 못했습니다.'));
    img.src = src;
});

// 그림의 가로 ÷ 세로 ( 못 읽으면 1 )
export async function imageRatio(src) {
    if (!src) return 1;
    try {
        const img = await loadImage(src);
        return img.naturalWidth && img.naturalHeight ? img.naturalWidth / img.naturalHeight : 1;
    } catch {
        return 1;
    }
}

const bytesOf = (dataUri) => Math.floor((dataUri.length - dataUri.indexOf(',') - 1) * 3 / 4);

// 올린 파일 → 줄인 data URI ( 긴 변 600px · PNG — 투명 배경을 지킨다 / 너무 크면 JPG 는 JPEG 로 ) · 400KB 를 넘으면 오류
export async function fileToStamp(file) {
    if (!/^image\/(png|jpeg)$/.test(file?.type ?? '')) throw new Error('PNG · JPG 그림만 올릴 수 있습니다.');
    const url = URL.createObjectURL(file);
    try {
        const img = await loadImage(url);
        const k = Math.min(1, MAX_PX / Math.max(img.naturalWidth, img.naturalHeight));
        const w = Math.max(1, Math.round(img.naturalWidth * k)), h = Math.max(1, Math.round(img.naturalHeight * k));
        const canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
        let src = canvas.toDataURL('image/png');
        if (bytesOf(src) > MAX_BYTES && file.type === 'image/jpeg') src = canvas.toDataURL('image/jpeg', 0.9);   // 사진 같은 JPG 는 PNG 로 크다
        if (bytesOf(src) > MAX_BYTES) throw new Error(`그림이 너무 큽니다. ( 줄인 뒤 ${Math.round(bytesOf(src) / 1024)}KB · ${MAX_BYTES / 1024}KB 까지 )`);
        return { src, ratio: w / h };
    } finally {
        URL.revokeObjectURL(url);
    }
}

// 새 도장 — 상자 가운데쯤 · 가로 widthMm ( 상자보다 크면 줄인다 )
export function newStamp(src, ratio, boxW, boxH, widthMm) {
    let w = Math.min(widthMm, boxW * 0.9), h = w / ratio;
    if (h > boxH * 0.9) { h = boxH * 0.9; w = h * ratio; }
    const r1 = (v) => Math.round(v * 10) / 10;
    return {
        id: `st${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`,
        src, x_mm: r1((boxW - w) / 2), y_mm: r1((boxH - h) / 2), w_mm: r1(w), h_mm: r1(h),
    };
}
