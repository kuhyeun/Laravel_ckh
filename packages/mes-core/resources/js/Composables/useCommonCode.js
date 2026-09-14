import { reactive, ref, onMounted } from 'vue';
import { route } from 'ziggy-js';

// 공통코드 조회 ( GET /common/codes ) + 그룹 단위 캐시
//   같은 페이지 세션 안에서는 한 번만 요청한다. 코드관리에서 저장·삭제하면 clearCommonCodeCache() 로 비운다.
const cache = new Map();   // group_key → Promise<[{ key, name, value }]>

export async function fetchCommonCodes( groups ) {
    const missing = groups.filter( g => !cache.has( g ) );

    if( missing.length ) {
        const request = window.axios.get( route( 'common.codes' ), { params: { groups: missing } } )
            .then( ({ data }) => data.codes ?? {} );

        missing.forEach( g => {
            const p = request.then( codes => codes[g] ?? [] );
            // 실패한 요청은 캐시에 남기지 않음 ( 다음 호출에서 재시도 )
            p.catch( () => cache.delete( g ) );
            cache.set( g, p );
        });
    };

    const lists = await Promise.all( groups.map( g => cache.get( g ) ) );
    return Object.fromEntries( groups.map( (g, i) => [ g, lists[i] ] ) );
}

export function clearCommonCodeCache( groups = null ) {
    if( groups ) {
        groups.forEach( g => cache.delete( g ) );
    } else {
        cache.clear();
    };
}

/**
 * 화면에서 사용
 *   const { codes, options } = useCommonCode( ['UNIT'] );
 *   <BaseSelect :options="options('UNIT')" v-model="form.spec_unit" />
 * @param {string[]} groups - 조회할 코드 그룹키
 */
export function useCommonCode( groups ) {
    const codes   = reactive( Object.fromEntries( groups.map( g => [ g, [] ] ) ) );
    const loading = ref( false );
    const error   = ref( null );

    // BaseSelect 옵션 형식 [{ value, label }] ( value = 코드키 )
    const options = ( group ) => ( codes[group] ?? [] ).map( c => ({ value: c.key, label: c.name }) );

    // 코드키 → 코드명 ( 그리드 formatter 등 )
    const nameOf = ( group, key ) => ( codes[group] ?? [] ).find( c => c.key === key )?.name ?? key;

    async function load() {
        loading.value = true;
        error.value   = null;
        try {
            Object.assign( codes, await fetchCommonCodes( groups ) );
        } catch( e ) {
            error.value = e;
        } finally {
            loading.value = false;
        }
    }

    onMounted( load );

    return { codes, options, nameOf, loading, error, reload: load };
}
