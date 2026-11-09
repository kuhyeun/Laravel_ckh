export function getRandomColor() {
    const r = Math.floor((Math.random() * 127) + 127);
    const g = Math.floor((Math.random() * 127) + 127);
    const b = Math.floor((Math.random() * 127) + 127);
    
    const color = `#${(1 << 24 | r << 16 | g << 8 | b).toString(16).slice(1).toUpperCase()}`;
    const brightness = 0.2126 * r + 0.7152 * g + 0.0722 * b;
    const isLight    = brightness > 128;

    // 매칭된 색상의 isLight 가 true 인 경우 TextColor를 어두운계열로 하는것이 좋음
    
    return color;
}
