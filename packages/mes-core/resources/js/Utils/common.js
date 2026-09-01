export function getNowDate( time = false ) {
    const now = new Date();

    let date_full_text = '';
    let year  = now.getFullYear();
    let month = now.getMonth() + 1;
    let day   = now.getDate();

    month = month >= 10 ? month : '0' + month;
    day = day >= 10 ? day : '0' + day;

    date_full_text = year + '-' + month + '-' + day;

    if( time ) {
        let hour   = now.getHours();
        let minute = now.getMinutes();
        let second = now.getSeconds();

        hour   = hour >= 10 ? hour : '0' + hour;
        minute = minute >= 10 ? minute : '0' + minute;
        second = second >= 10 ? second : '0' + second;

        date_full_text += ' ' + hour + ':' + minute + ':' + second;
    }

    return date_full_text;
}

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
