<!-- 작업지시 하나의 자재출고 패널 — docs/screens/50-생산.md §12
     - 자재 줄마다 계산 소요( ⓘ 계산 과정 ) · 규격 묶음 · 후보 LOT( 선입선출 ) 채움 → 자재 목록 아래 오른쪽 [출고]( 출고일시 · 비고 같이 )
     - 아래 : 이 지시의 출고 이력 — 체크 → [출고 취소] ( 보유분 안에서만 )
     - 쓰는 곳 : 자재출고 화면( Production/MaterialIssue ) · 공정실적 화면의 [출고] ( Production/OpResult — 그 화면 권한으로 부르는 주소 )
               · 재고관리 LOT 창의 [출고] ( preferBalanceIdx — 그 LOT 을 먼저 채운다 )
     - routes : { plan, history, save, cancel, returnPlan, returnSave, returnCancel } route name. 처리 후 changed, 알림은 flash( text, error )
     - 탭 [출고] / [반납]( MaterialReturnPanel — §15-1 ). 완료 · 취소된 줄은 출고를 끄고 반납 탭으로 연다
     - 대체 자재 ( docs/대체자재-수주자재-설계.md §5.4 ) : 주 자재 카드 바로 뒤 "대체" 카드 — 후보만 보이고 채우지 않는다( 사람이 고른다 )
       주 자재의 남음 · 부족은 대체 자재 출고 · 이번 채움을 주 자재 기준으로 환산해 함께 본다 ( 대체 1 = 주 ratio_to_main )
     - BOM 외 자재 ( 11-13 ) : 자재 목록 아래 [+ 자재 추가] 로 그 지시의 BOM 에 없는 자재 줄을 더한다 ( 정책 material.issue_extra · routes.extraAdd 가 있을 때 )
       'BOM 외' 카드 — 소요 · 남음 계산 없이 후보 LOT 만 · 출고한 적이 없으면 ✕ 로 지운다 · 출고 이력에도 'BOM 외' 표시
     - 입력 방식 ( 11-18 · 정책 material.issue_scan + routes.scan ) : 머리줄의 알약 모양 단추 [ BOM 입력 | 바코드 스캔 ] — 출고 / 반납 탭과 다른 모양
       BOM 입력    : 지금까지의 화면 ( 자재 카드 · 자동 채움 · 손으로 수량 )
       바코드 스캔 : 스캔 칸에 찍으면( 번호 + Enter ) 그 LOT 이 「스캔한 LOT」 표에 1 로 올라가고, 다시 찍을 때마다 1 씩 — 수량은 손으로 고칠 수 있다
                     자동 채움 없음 · 위에 BOM 자재의 이름 · 필요 · 출고 · 남음 · 이번 스캔을 같이 본다 · 지시에 없는 자재면 BOM 외 줄로 더한 뒤 1
       방식을 바꾸면 넣어 둔 수량은 버리고 다시 불러온다 · 고른 방식은 브라우저에 기억
       스캔 칸 포커스 지키기 : 빈 곳 · 단추를 눌러 포커스가 빠지면 스캔 칸으로 되돌린다 ( 다른 입력 칸에 있을 때 · 알림창이 떠 있을 때는 그대로 ) -->
<template>
    <div ref="rootEl" class="flex flex-col min-h-0">
        <div v-if="!order" class="flex-1 flex items-center justify-center text-sm text-gray-400 py-6">
            {{ planLoading ? '불러오는 중…' : '출고 정보를 불러오지 못했습니다.' }}
        </div>
        <template v-else>
            <div class="flex items-center gap-1 px-2 border-b shrink-0">
                <button v-for="t in MODE_TABS" :key="t.key" type="button"
                    class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                    :class="mode === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    @click="mode = t.key">{{ t.label }}</button>
                <span v-if="!isOpen" class="ml-2 text-xs text-gray-400">{{ order.status === 'DONE' ? '완료' : '취소' }}된 줄은 출고할 수 없고 남은 자재를 반납만 합니다</span>
            </div>
            <MaterialReturnPanel v-if="mode === 'return'" class="flex-1" :wo-idx="woIdx" :can-write="canWrite" :can-cancel="canCancel" :routes="routes"
                @changed="onReturnChanged" @flash="(t, e) => emit('flash', t, e)" />
            <template v-else>
            <div class="px-3 py-2 bg-gray-50/70 shrink-0 flex items-center gap-3 flex-wrap">
                <span class="text-sm font-semibold">{{ order.wo_no }}</span>
                <span class="text-sm text-gray-600">{{ order.item_code }} {{ order.item_name }} · 지시 {{ fmtNum(order.target_qty) }} {{ order.spec_unit }}</span>
                <span v-if="order.lot_no" class="text-xs text-gray-400">LOT {{ order.lot_no }}</span>
                <div class="flex-1"></div>
                <!-- 입력 방식 ( 11-18 ) — 출고 / 반납 탭과 헷갈리지 않게 알약 모양 -->
                <div v-if="canScan" class="inline-flex items-center p-0.5 rounded-full bg-gray-200/80" title="출고 수량을 넣는 방식">
                    <button v-for="t in INPUT_MODES" :key="t.key" type="button" :disabled="planLoading"
                        class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs transition-colors"
                        :class="inputMode === t.key ? 'bg-white text-gray-900 font-semibold shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                        @click="setInputMode(t.key)">
                        <svg v-if="t.key === 'scan'" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path d="M2 4h1.5v12H2zM5 4h1v12H5zM7.5 4h2v12h-2zM11 4h1v12h-1zM13.5 4h1.5v12h-1.5zM16.5 4H18v12h-1.5z" /></svg>
                        <svg v-else viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5"><path d="M4 5h12M4 10h12M4 15h12" stroke-linecap="round" /></svg>
                        {{ t.label }}
                    </button>
                </div>
                <BaseButton size="sm" variant="secondary" :disabled="planLoading" @click="reloadPlan">{{ inputMode === 'scan' ? '새로고침' : '계산 다시' }}</BaseButton>
            </div>
            <p v-if="errorText" class="px-3 py-1 text-xs text-red-500 bg-red-50 shrink-0">{{ errorText }}</p>

            <!-- 바코드 스캔 방식 ( 11-18 ) -->
            <div v-if="inputMode === 'scan'" class="flex-1 min-h-0 overflow-auto px-3 py-2 space-y-3">
                <div class="flex items-center gap-3 px-3 py-2.5 rounded-md border-2 border-blue-200 bg-blue-50/50">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-6 h-6 shrink-0 text-blue-500"><path d="M2 4h1.5v12H2zM5 4h1v12H5zM7.5 4h2v12h-2zM11 4h1v12h-1zM13.5 4h1.5v12h-1.5zM16.5 4H18v12h-1.5z" /></svg>
                    <BaseInput ref="scanEl" v-model="scan.text" size="lg" class="w-80 shrink-0" placeholder="바코드 · QR 을 찍으세요" :select-on-focus="true" @keydown.enter.prevent="onScanEnter" />
                    <span v-if="scan.msg" class="text-sm min-w-0 truncate" :class="scan.error ? 'text-red-600 font-semibold' : 'text-green-700'" :title="scan.msg">{{ scan.msg }}</span>
                    <span v-else class="text-xs text-gray-500 min-w-0 truncate">자재 라벨을 찍을 때마다 그 LOT 의 수량이 1 씩 오릅니다 · LOT 번호를 직접 넣고 Enter 도 됩니다</span>
                </div>

                <!-- BOM 자재 : 이름 · 필요 수량 ( 보기만 ) -->
                <div>
                    <p class="mb-1 text-xs font-semibold text-gray-600">이 지시의 자재 <span class="font-normal text-gray-400">{{ materials.length }} 줄</span></p>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[720px] table-fixed text-xs border">
                            <colgroup><col><col class="w-24"><col class="w-24"><col class="w-24"><col class="w-28"><col class="w-8"></colgroup>
                            <thead class="bg-gray-50 text-gray-500">
                                <tr><th class="text-left px-2 py-1.5">자재</th><th class="text-right px-2 py-1.5">필요</th><th class="text-right px-2 py-1.5">출고</th><th class="text-right px-2 py-1.5">남음</th><th class="text-right px-2 py-1.5">이번 스캔</th><th></th></tr>
                            </thead>
                            <tbody>
                                <tr v-if="!materials.length"><td colspan="6" class="px-2 py-2 text-center text-gray-400">이 지시에 자재가 없습니다 ( 사용 중 BOM 없음 ). 찍은 자재는 BOM 외로 더해집니다.</td></tr>
                                <tr v-for="m in materials" :key="m.work_order_material_idx" class="border-t border-gray-100" :class="filled(m) > 1e-9 ? 'bg-blue-50/40' : ''">
                                    <td class="px-2 py-1.5 truncate" :class="m.substitute_of_idx ? 'pl-6' : ''" :title="`${m.item_code} ${m.item_name}`">
                                        <span v-if="m.substitute_of_idx" class="shrink-0 whitespace-nowrap mr-1 px-1 rounded bg-gray-100 text-gray-600" :title="`${m.main_item_code} 대신 낼 수 있습니다`">대체</span>
                                        <span v-if="m.is_extra" class="shrink-0 whitespace-nowrap mr-1 px-1 rounded bg-purple-50 text-purple-700" :title="m.extra_reason || 'BOM 에 없는 자재'">BOM 외</span>
                                        <b>{{ m.item_code }}</b> <span class="text-gray-600">{{ m.item_name }}</span>
                                    </td>
                                    <td class="px-2 py-1.5 text-right">{{ m.is_extra || m.substitute_of_idx || m.calc_qty === null ? '—' : `${fmtNum(m.calc_qty)} ${unitOf(m)}` }}</td>
                                    <td class="px-2 py-1.5 text-right text-gray-500">{{ fmtNum(m.issued_qty) }}</td>
                                    <td class="px-2 py-1.5 text-right" :class="m.need_qty > 0 ? 'text-blue-700 font-semibold' : 'text-green-600'">{{ m.is_extra ? '—' : fmtNum(m.need_qty) }}</td>
                                    <td class="px-2 py-1.5 text-right font-semibold" :class="!m.is_extra && filled(m) > m.need_qty + 1e-9 ? 'text-amber-600' : ''" :title="!m.is_extra && filled(m) > m.need_qty + 1e-9 ? '남은 소요보다 많습니다' : ''">{{ filled(m) > 1e-9 ? fmtNum(filled(m)) : '' }}</td>
                                    <td class="text-center"><button v-if="m.is_extra && m.removable && canIssue" type="button" class="px-1 text-gray-400 hover:text-red-500" title="이 자재 줄 지우기 ( 출고한 적이 없는 줄만 )" @click="removeExtra(m)">✕</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 스캔한 LOT : 수량은 손으로 고칠 수 있다 -->
                <div>
                    <p class="mb-1 text-xs font-semibold text-gray-600">스캔한 LOT <span class="font-normal text-gray-400">{{ scannedRows.length }} 줄</span></p>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[720px] table-fixed text-xs border">
                            <colgroup><col><col class="w-56"><col class="w-28"><col class="w-24"><col class="w-32"><col class="w-8"></colgroup>
                            <thead class="bg-gray-50 text-gray-500">
                                <tr><th class="text-left px-2 py-1.5">자재</th><th class="text-left px-2 py-1.5">LOT</th><th class="text-left px-2 py-1.5">창고</th><th class="text-right px-2 py-1.5">잔고</th><th class="text-right px-2 py-1.5">출고</th><th></th></tr>
                            </thead>
                            <tbody>
                                <tr v-if="!scannedRows.length"><td colspan="6" class="px-2 py-4 text-center text-gray-400">아직 찍은 자재가 없습니다.</td></tr>
                                <tr v-for="{ m, l } in scannedRows" :key="`${m.work_order_material_idx}:${l.stock_balance_idx}`" class="border-t border-gray-100 transition-colors" :class="l._hit ? 'bg-yellow-100' : ''" :data-scan-row="`${m.work_order_material_idx}:${l.stock_balance_idx}`">
                                    <td class="px-2 py-1.5 truncate" :title="`${m.item_code} ${m.item_name}`"><span v-if="m.is_extra" class="shrink-0 whitespace-nowrap mr-1 px-1 rounded bg-purple-50 text-purple-700">BOM 외</span>{{ m.item_code }} {{ m.item_name }}</td>
                                    <td class="px-2 py-1.5 truncate" :title="l.lot_no">{{ l.lot_no }}<span v-if="l.remnant" class="shrink-0 whitespace-nowrap ml-1 px-1 rounded bg-amber-50 text-amber-700">잔재</span></td>
                                    <td class="px-2 py-1.5 truncate" :title="l.warehouse_name">{{ l.warehouse_name }}</td>
                                    <td class="px-2 py-1.5 text-right">{{ fmtNum(l.qty) }}</td>
                                    <td class="px-1.5 py-1"><BaseInput v-model="l.fill" type="number" min="0" size="md" class="w-full text-right" :class="over(l) ? '!border-red-400 !text-red-600' : ''" /></td>
                                    <td class="text-center"><button type="button" class="px-1 text-gray-400 hover:text-red-500" title="이 LOT 빼기" @click="unscan(l)">✕</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-else class="flex-1 min-h-0 overflow-auto px-3 py-2 space-y-3">
                <p v-if="!materials.length" class="py-6 text-center text-sm text-gray-400">이 지시에 자재가 없습니다 ( 사용 중 BOM 없음 ).</p>

                <div v-for="m in materials" :key="m.work_order_material_idx" class="border rounded" :class="m.substitute_of_idx ? 'ml-6 border-dashed border-gray-300' : 'border-gray-200'">
                    <!-- 자재 머리 -->
                    <div class="flex items-center gap-2 px-2 py-1.5 cursor-pointer select-none" :class="m.substitute_of_idx ? 'bg-white' : 'bg-gray-50'" @click="m._open = !m._open">
                        <span class="text-gray-400 text-xs w-3">{{ m._open ? '▾' : '▸' }}</span>
                        <span v-if="m.substitute_of_idx" class="shrink-0 whitespace-nowrap text-[10px] px-1 rounded bg-gray-100 text-gray-600" :title="`${m.main_item_code} 대신 낼 수 있습니다 · 대체 1 = 주 자재 ${fmtNum(m.ratio_to_main)}`">대체 · {{ m.main_item_code }} 대신</span>
                        <span v-if="m.is_extra" class="shrink-0 whitespace-nowrap text-[10px] px-1 rounded bg-purple-50 text-purple-700 font-semibold" :title="m.extra_reason ? `BOM 에 없는 자재 — ${m.extra_reason}` : '이 작업지시의 BOM 에 없는 자재입니다 ( 출고 창에서 추가 )'">BOM 외</span>
                        <span class="text-sm font-semibold">{{ m.item_code }}</span>
                        <span class="text-sm text-gray-600 truncate">{{ m.item_name }}</span>
                        <span v-if="!m.is_extra" class="text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-700" :title="m.calc_name">{{ m.calc_code }}</span>
                        <span v-if="m.op_label && !m.is_extra" class="text-xs text-gray-400">투입 {{ m.op_label }}</span>
                        <span v-if="m.is_extra && m.extra_reason" class="text-xs text-gray-400 truncate">{{ m.extra_reason }}</span>
                        <div class="flex-1"></div>
                        <span v-if="m.is_extra" class="text-xs text-gray-500">
                            소요 없음 · 출고 <b class="text-gray-800">{{ fmtNum(m.issued_qty) }}</b> · 보유 {{ fmtNum(m.held_qty) }} {{ unitOf(m) }}
                        </span>
                        <button v-if="m.is_extra && m.removable && canIssue" type="button" class="shrink-0 px-1 text-gray-400 hover:text-red-500" title="이 자재 줄 지우기 ( 출고한 적이 없는 줄만 )" @click.stop="removeExtra(m)">✕</button>
                        <span v-if="!m.is_extra" class="text-xs text-gray-500">
                            계산 <b class="text-gray-800">{{ m.calc_qty === null ? '—' : fmtNum(m.calc_qty) }}</b>
                            · 출고 {{ fmtNum(m.issued_qty) }}<span v-if="m.substitute_issued_equiv > 1e-9" class="text-gray-400" title="대체 자재 출고를 주 자재 기준으로 바꾼 몫"> ( 대체 환산 {{ fmtNum(m.substitute_issued_equiv) }} 포함 )</span>
                            · 보유 {{ fmtNum(m.held_qty) }}
                            · 남음 <b :class="m.need_qty > 0 ? 'text-blue-700' : 'text-green-600'">{{ fmtNum(m.need_qty) }}</b>
                            {{ unitOf(m) }}<template v-if="m.substitute_of_idx"> ( 주 자재 남음을 이 자재 기준으로 )</template>
                        </span>
                        <button v-if="!m.is_extra && !m.uses_spec && m.groups[0]?.trace" type="button" class="inline-flex items-center justify-center w-5 h-5 shrink-0 text-gray-400 hover:text-blue-600" title="계산 과정" @click.stop="toggleTrace(m.groups[0])"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="w-4 h-4"><circle cx="10" cy="10" r="7.5" /><path d="M10 9v5" stroke-linecap="round" /><circle cx="10" cy="6.3" r="0.9" fill="currentColor" stroke="none" /></svg></button>
                    </div>

                    <div v-if="m._open" class="px-2 py-1.5">
                        <p v-for="(w, i) in m.warnings" :key="i" class="text-xs text-amber-600">⚠ {{ w }}</p>
                        <TracePanel v-if="!m.uses_spec && m.groups[0]?._trace" :trace="m.groups[0].trace" :unit="unitOf(m)" />

                        <div v-for="g in m.groups" :key="g.key" class="mt-1">
                            <div v-if="m.uses_spec" class="flex items-center gap-2 text-xs py-1" :class="g.excluded ? 'text-gray-400' : 'text-gray-700'">
                                <span class="font-semibold">규격 {{ g.spec === null ? '없음' : fmtNum(g.spec) }}</span>
                                <span>재고 {{ fmtNum(g.stock) }}</span>
                                <span v-if="g.excluded">· {{ g.reason }} — 후보 제외</span>
                                <span v-else>· 계산 <b>{{ fmtNum(g.value) }}</b> {{ unitOf(m) }}</span>
                                <span v-if="g.key === m.default_key" class="shrink-0 whitespace-nowrap px-1 rounded bg-green-50 text-green-700">기본</span>
                                <button v-if="g.trace && g.spec !== null" type="button" class="inline-flex items-center justify-center w-5 h-5 shrink-0 text-gray-400 hover:text-blue-600" title="계산 과정" @click="toggleTrace(g)"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="w-4 h-4"><circle cx="10" cy="10" r="7.5" /><path d="M10 9v5" stroke-linecap="round" /><circle cx="10" cy="6.3" r="0.9" fill="currentColor" stroke="none" /></svg></button>
                            </div>
                            <TracePanel v-if="m.uses_spec && g._trace" :trace="g.trace" :unit="unitOf(m)" />

                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[704px] table-fixed text-xs">
                                    <colgroup>
                                        <col class="w-56"><col><col class="w-24"><col class="w-24"><col class="w-32">
                                    </colgroup>
                                    <thead class="bg-gray-50 text-gray-500">
                                        <tr><th class="text-left px-2 py-1.5">LOT</th><th class="text-left px-2 py-1.5">창고</th><th class="text-center px-2 py-1.5">입고일</th><th class="text-right px-2 py-1.5">잔고</th><th class="text-right px-2 py-1.5">출고</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-if="!g.lots.length"><td colspan="5" class="text-gray-400 px-2 py-1.5">출고할 수 있는 재고가 없습니다.</td></tr>
                                        <tr v-for="l in g.lots" :key="l.stock_balance_idx" class="border-t border-gray-100">
                                            <td class="px-2 py-1.5">
                                                {{ l.lot_no || '( LOT 미사용 )' }}
                                                <span v-if="l.remnant" class="shrink-0 whitespace-nowrap ml-1 px-1 rounded bg-amber-50 text-amber-700">잔재</span>
                                            </td>
                                            <td class="truncate px-2 py-1.5" :title="l.warehouse_name">{{ l.warehouse_name }}</td>
                                            <td class="text-center text-gray-500 px-2 py-1.5">{{ l.in_at }}</td>
                                            <td class="text-right px-2 py-1.5">{{ fmtNum(l.qty) }}</td>
                                            <td class="px-1.5 py-1">
                                                <BaseInput v-model="l.fill" type="number" min="0" size="md" class="w-full text-right"
                                                    :class="over(l) ? '!border-red-400 !text-red-600' : ''" :disabled="!canIssue" />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- 대체 카드 : 이번 채움이 주 자재 몇 개분인지 -->
                        <p v-if="m.substitute_of_idx" class="text-right text-xs mt-1 text-gray-500">
                            이번 출고 <b>{{ fmtNum(filled(m)) }}</b> {{ unitOf(m) }} · 주 자재 {{ fmtNum(filled(m) * m.ratio_to_main) }} 몫
                        </p>
                        <!-- BOM 외 카드 : 소요가 없다 — 이번 출고만 -->
                        <p v-else-if="m.is_extra" class="text-right text-xs mt-1 text-gray-500">이번 출고 <b>{{ fmtNum(filled(m)) }}</b> {{ unitOf(m) }}</p>
                        <!-- 주 자재 카드 : 대체 자재 채움을 환산해 함께 본다 -->
                        <p v-else class="text-right text-xs mt-1" :class="covered(m) > m.need_qty + 1e-9 ? 'text-amber-600' : 'text-gray-500'">
                            이번 출고 <b>{{ fmtNum(filled(m)) }}</b> {{ unitOf(m) }}<span v-if="covered(m) - filled(m) > 1e-9" class="text-gray-400"> + 대체 환산 {{ fmtNum(covered(m) - filled(m)) }}</span>
                            <span v-if="m.need_qty - covered(m) > 1e-9" class="text-red-500"> · 부족 {{ fmtNum(m.need_qty - covered(m)) }}</span>
                            <span v-else-if="covered(m) > m.need_qty + 1e-9"> · 남은 소요보다 {{ fmtNum(covered(m) - m.need_qty) }} 많음</span>
                        </p>
                    </div>
                </div>

                <!-- BOM 외 자재 더하기 ( 11-13 ) -->
                <div v-if="canAddExtra" class="flex items-center gap-2 px-2 py-1.5 border border-dashed border-gray-300 rounded">
                    <span class="text-xs text-gray-500 shrink-0">BOM 에 없는 자재</span>
                    <ItemPicker v-model="extra.item_idx" :remote="EXTRA_REMOTE" :exclude="materialItemIdxs" class="w-72" placeholder="+ 자재 추가" :disabled="extra.busy" @select="addExtra" />
                    <BaseInput v-model="extra.reason" size="md" class="w-56" placeholder="사유 ( 선택 )" maxlength="200" :disabled="extra.busy" />
                    <span class="text-xs text-gray-400 truncate">자재를 고르면 아래에 'BOM 외' 줄이 생깁니다 · 수량을 넣고 [출고]</span>
                </div>
            </div>

            <!-- 출고 실행 : 자재 목록 바로 아래 오른쪽 ( 11-05 — 위에 있으면 헷갈림 ) -->
            <div v-if="canIssue" class="shrink-0 flex items-center justify-end gap-2 px-3 py-2 border-t border-gray-200 bg-gray-50/70">
                <span class="mr-auto text-xs text-gray-500">{{ fillTotal ? `이번 출고 ${materials.filter(m => filled(m) > 1e-9).length} 자재` : (inputMode === 'scan' ? '자재 라벨을 찍으세요' : '출고할 수량을 채우세요') }}</span>
                <label class="text-xs text-gray-500">출고일시</label>
                <BaseDatePicker v-model="form.occurred_at" mode="datetime" size="md" class="w-44" />
                <BaseInput v-model="form.remark" size="md" class="w-40" placeholder="비고" maxlength="200" />
                <BaseButton variant="primary" :disabled="saving || planLoading || !fillTotal" @click="save">출고</BaseButton>
            </div>

            <!-- 출고 이력 -->
            <div class="shrink-0 border-t border-gray-200 max-h-72 flex flex-col">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-50/70">
                    <span class="text-sm font-semibold text-gray-600">출고 이력 <span class="text-xs text-gray-400">{{ history.length }} 줄</span></span>
                    <div class="flex-1"></div>
                    <BaseButton v-if="canCancel && isOpen" variant="danger" :disabled="!checked.length || saving" @click="cancelChecked">출고 취소</BaseButton>
                </div>
                <div class="overflow-auto">
                    <table class="w-full min-w-[1008px] table-fixed text-xs">
                        <colgroup>
                            <col class="w-8"><col class="w-32"><col class="w-36">
                            <col><col class="w-32"><col class="w-28">
                            <col class="w-36"><col class="w-14"><col class="w-24">
                        </colgroup>
                        <thead class="bg-gray-50 text-gray-500 sticky top-0">
                            <tr>
                                <th class="px-2 py-1.5"></th><th class="text-left px-2 py-1.5">전표</th><th class="text-left px-2 py-1.5">출고일시</th>
                                <th class="text-left px-2 py-1.5">자재</th><th class="text-left px-2 py-1.5">LOT</th><th class="text-left px-2 py-1.5">창고</th>
                                <th class="text-right px-2 py-1.5">수량</th><th class="text-center px-2 py-1.5">상태</th><th class="text-left px-2 py-1.5">등록자</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!history.length"><td colspan="9" class="px-2 py-2 text-center text-gray-400">출고 이력이 없습니다.</td></tr>
                            <tr v-for="h in history" :key="h.stock_txn_line_idx" class="border-t border-gray-100" :class="h.status === 'CANCELED' ? 'text-gray-400 line-through' : ''">
                                <td class="text-center px-2 py-1.5"><input v-if="canCancel && isOpen && h.status === 'DONE'" type="checkbox" class="row-check" :value="h.stock_txn_line_idx" v-model="checked" /></td>
                                <td class="px-2 py-1.5">{{ h.txn_no }}</td>
                                <td class="px-2 py-1.5">{{ h.occurred_at.replace('T', ' ') }}</td>
                                <td class="truncate px-2 py-1.5" :title="`${h.item_code} ${h.item_name}${h.is_extra ? ' — BOM 외 자재' + (h.extra_reason ? ' ( ' + h.extra_reason + ' )' : '') : ''}`"><span v-if="h.is_extra" class="shrink-0 whitespace-nowrap mr-1 px-1 rounded bg-purple-50 text-purple-700 no-underline">BOM 외</span>{{ h.item_code }} {{ h.item_name }}</td>
                                <td class="truncate px-2 py-1.5" :title="h.lot_no">{{ h.lot_no }}</td>
                                <td class="truncate px-2 py-1.5" :title="h.warehouse_name">{{ h.warehouse_name }}</td>
                                <td class="text-right px-2 py-1.5">{{ qtyText(h.qty, h.spec_unit, h.spec_value) }}<span v-if="h.consumed_qty" class="text-gray-400"> ( 사용 {{ fmtNum(h.consumed_qty) }} )</span></td>
                                <td class="text-center px-2 py-1.5">{{ h.status === 'CANCELED' ? '취소' : '출고' }}</td>
                                <td class="px-2 py-1.5">{{ h.user_name }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </template>
        </template>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, h, nextTick, onBeforeUnmount } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import ItemPicker from '@core/Components/Common/ItemPicker.vue';
import { showAlert } from '@core/Utils/message';
import { qtyText } from '@core/Components/Stock/stockCodes';
import MaterialReturnPanel from '@core/Components/Production/MaterialReturnPanel.vue';
import { useLatest } from '@core/Composables/useLatest';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    woIdx:     { type: Number, required: true },
    canWrite:  { type: Boolean, default: false },
    canCancel: { type: Boolean, default: false },
    preferBalanceIdx: { type: Number, default: null },   // 이 잔고 행을 선입선출보다 먼저 채운다 ( 같은 규격 묶음 안에서 )
    initialMode: { type: String, default: 'issue' },     // 처음 탭 : issue 출고 · return 반납 ( 공정실적의 [출고] · [반납] — 11-12 )
    routes:    { type: Object, default: () => ({
        plan: 'production.materialIssue.plan', history: 'production.materialIssue.history',
        save: 'production.materialIssue.save', cancel: 'production.materialIssue.cancel',
        returnPlan: 'production.materialIssue.returnPlan', returnSave: 'production.materialIssue.returnSave', returnCancel: 'production.materialIssue.returnCancel',
        leftoverSave: 'production.materialIssue.leftoverSave', leftoverCancel: 'production.materialIssue.leftoverCancel',   // 잔재 입고 ( 11-12 )
        extraAdd: 'production.materialIssue.extraAdd', extraRemove: 'production.materialIssue.extraRemove',                 // BOM 외 자재 줄 ( 11-13 )
        scan: 'production.materialIssue.scan',                                                                              // 바코드 · QR 스캔 ( 11-18 )
    }) },
});
const emit = defineEmits(['changed', 'flash']);

const unitOf = (m) => m.result_kind === 'SPEC_COUNT' ? '개' : (m.spec_unit ?? '');

// 계산 과정 ( 설계 §9 ⓘ ) — 식 · 대입 · 결과 · 변수마다 출처
const TracePanel = (p) => h('div', { class: 'my-1 px-2 py-1.5 rounded bg-slate-50 border border-slate-200 text-xs font-mono text-slate-700 space-y-0.5' }, [
    h('div', p.trace.expr),
    h('div', p.trace.subst),
    h('div', { class: 'font-semibold' }, `= ${p.trace.value === null ? '—' : fmtNum(p.trace.value)} ${p.unit}` + (p.trace.value !== null && p.trace.raw !== p.trace.value ? `  ( 계산값 ${fmtNum(p.trace.raw)} )` : '')),
    h('table', { class: 'mt-1 font-sans' }, p.trace.vars.map(v => h('tr', [
        h('td', { class: 'pr-3 font-mono' }, v.name),
        h('td', { class: 'pr-3 text-right' }, v.value === null ? '( 없음 )' : fmtNum(v.value)),
        h('td', { class: 'text-slate-500' }, v.source),
    ]))),
]);
TracePanel.props = ['trace', 'unit'];

const order       = ref(null);
const materials   = ref([]);
const history     = ref([]);
const checked     = ref([]);
const planLoading = ref(false);
const saving      = ref(false);
const errorText   = ref('');
const form        = reactive({ occurred_at: '', remark: '' });

// 출고 / 반납 — 출고는 대기 · 진행인 줄만
const MODE_TABS = [{ key: 'issue', label: '출고' }, { key: 'return', label: '반납' }];
const mode      = ref(props.initialMode === 'return' ? 'return' : 'issue');
const isOpen    = computed(() => ['WAIT', 'RUN'].includes(order.value?.status));
const canIssue  = computed(() => props.canWrite && isOpen.value);
async function onReturnChanged() {
    await loadPlan();
    emit('changed');
}

const num = (v) => { const n = Number(v); return Number.isFinite(n) && n > 0 ? n : 0; };
const filled = (m) => m.groups.reduce((s, g) => s + g.lots.reduce((t, l) => t + num(l.fill), 0), 0);
const over = (l) => num(l.fill) > l.qty + 1e-9;
const fillTotal = computed(() => materials.value.reduce((s, m) => s + filled(m), 0));
// 주 자재 카드의 이번 채움 ( 주 자재 기준 ) = 자기 채움 + 그 대체 카드 채움 × 환산
const covered = (m) => filled(m) + materials.value
    .filter(x => x.substitute_of_idx === m.work_order_material_idx)
    .reduce((s, x) => s + filled(x) * num(x.ratio_to_main), 0);

function toggleTrace(g) { g._trace = !g._trace; }

// ---- BOM 외 자재 ( 11-13 ) — 고르면 그 지시에 자재 줄이 생긴다 ( 서버 ) → 다시 불러와 후보 LOT 을 보여 준다 ----
const EXTRA_REMOTE = { types: ['MATERIAL'] };
const extraAllowed = ref(false);
const extra        = reactive({ item_idx: '', reason: '', busy: false });
const canAddExtra  = computed(() => canIssue.value && extraAllowed.value && !!props.routes.extraAdd);
const materialItemIdxs = computed(() => materials.value.map(m => m.item_idx));
// 다시 불러와도 이미 넣어 둔 출고 수량은 남긴다 ( 잔고 행 기준 )
const keepFills = () => Object.fromEntries(materials.value.flatMap(m => m.groups.flatMap(g => g.lots.filter(l => num(l.fill) > 0).map(l => [`${m.work_order_material_idx}:${l.stock_balance_idx}`, l.fill]))));
function restoreFills(saved) {
    materials.value.forEach(m => m.groups.forEach(g => g.lots.forEach(l => {
        const v = saved[`${m.work_order_material_idx}:${l.stock_balance_idx}`];
        if (v === undefined) return;
        l.fill   = v;
        m._open  = true;                          // 채운 칸이 있는 자재는 펼쳐 둔다 ( 접히면 채운 수량이 안 보인다 )
        l._scanned = inputMode.value === 'scan';  // 바코드 스캔 방식 : 「스캔한 LOT」 표에 남긴다
    })));
}

// ---- 입력 방식 ( 11-18 ) : BOM 입력( 자재 카드 · 자동 채움 ) / 바코드 스캔( 찍은 만큼만 ) ----
const INPUT_MODES = [{ key: 'bom', label: 'BOM 입력' }, { key: 'scan', label: '바코드 스캔' }];
const MODE_KEY    = 'mes.materialIssue.inputMode';
const readPref    = () => { try { return localStorage.getItem(MODE_KEY) === 'scan' ? 'scan' : 'bom'; } catch { return 'bom'; } };
const scanAllowed = ref(false);
const modePref    = ref(readPref());
const inputMode   = computed(() => canScan.value && modePref.value === 'scan' ? 'scan' : 'bom');
async function setInputMode(key) {
    if (key === modePref.value || planLoading.value) return;
    if (fillTotal.value > 1e-9) {
        const r = await showAlert('입력 방식 바꾸기', '넣어 둔 출고 수량은 지워집니다. 바꿀까요?', 'warning', 'confirm');
        if (!r.isConfirmed) return;
    }
    modePref.value = key;
    try { localStorage.setItem(MODE_KEY, key); } catch { /* 저장 못 해도 그대로 */ }
    resetScan();
    await loadPlan();
    focusScan();
}
const focusScan = () => nextTick(() => { if (inputMode.value === 'scan') scanEl.value?.focus(); });

// 스캔 칸 포커스 지키기 — 스캐너는 포커스가 있는 곳에 글자를 넣는다. 포커스가 빠져 있으면 찍어도 아무 일이 없다
//   되돌리는 때 : 포커스가 아무 데도 없거나( 빈 곳을 누름 ) 이 창 안의 입력 칸이 아닌 것( 단추 · ✕ 등 )에 있을 때
//   그대로 두는 때 : 다른 입력 칸( 수량 · 비고 · 출고일시 … )에 있을 때 · 알림창이 떠 있을 때 · 이 창 밖의 것( 달력 펼침 등 )에 있을 때
const rootEl = ref(null);
const isEditable = (el) => !!el && (el.isContentEditable || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT'
    || (el.tagName === 'INPUT' && !['checkbox', 'radio', 'button', 'submit', 'reset', 'file'].includes(el.type)));
function keepScanFocus() {
    setTimeout(() => {   // 누른 것으로 포커스가 옮겨 간 뒤에 본다
        const input = scanEl.value?.el;
        if (inputMode.value !== 'scan' || mode.value !== 'issue' || !input || input.disabled || !input.offsetParent) return;
        if (document.querySelector('.swal2-container')) return;   // 알림 · 확인창
        const a = document.activeElement;
        if (a === input || isEditable(a)) return;
        if (a && a !== document.body && !rootEl.value?.contains(a)) return;
        input.focus({ preventScroll: true });
    }, 0);
}
const FOCUS_EVENTS = ['focusout', 'mouseup', 'touchend'];
let   focusGuard   = false;
function guardScanFocus(on) {
    if (on === focusGuard) return;
    focusGuard = on;
    FOCUS_EVENTS.forEach(ev => document[on ? 'addEventListener' : 'removeEventListener'](ev, keepScanFocus, true));
    window[on ? 'addEventListener' : 'removeEventListener']('focus', keepScanFocus);
    if (on) keepScanFocus();
}
// ( 켜고 끄는 watch 는 맨 아래 — canScan 이 선언된 뒤 )
// [새로고침] / [계산 다시] : 스캔 방식은 찍어 둔 수량을 남긴다
function reloadPlan() { return inputMode.value === 'scan' ? reloadKeeping() : loadPlan(); }
const scannedRows = computed(() => materials.value.flatMap(m => m.groups.flatMap(g => g.lots.filter(l => l._scanned).map(l => ({ m, l })))));
function unscan(l) { l.fill = ''; l._scanned = false; }

// ---- 바코드 · QR 스캔 ( 11-18 ) — 찍을 때마다 그 LOT 의 출고 칸 + 1 ( 번호 = 라벨의 바코드 번호 = LOT 번호 ) ----
const scanEl      = ref(null);
const scan        = reactive({ text: '', msg: '', error: false });
const canScan     = computed(() => canIssue.value && scanAllowed.value && !!props.routes.scan && route().has(props.routes.scan));
const scanCache   = new Map();   // 번호 → 서버 답 ( 같은 라벨을 이어 찍을 때 다시 묻지 않는다 )
const scanQueue   = [];          // 스캐너가 빠르게 이어 찍어도 순서대로
let   scanRunning = false;
function resetScan() { scanCache.clear(); scanQueue.length = 0; scan.text = ''; scan.msg = ''; scan.error = false; }
function scanSay(msg, error = false) { scan.msg = msg; scan.error = error; }

function onScanEnter() {
    const no = String(scan.text ?? '').trim();
    scan.text = '';
    if (!no) return;
    scanQueue.push(no);
    runScans();
}
async function runScans() {
    if (scanRunning) return;
    scanRunning = true;
    try {
        while (scanQueue.length) await scanOne(scanQueue.shift());
    } finally {
        scanRunning = false;
    }
}
// 그 LOT 의 잔고 줄 — 아직 잔고가 남은 줄 먼저 ( 같은 LOT 이 창고 둘에 있을 수 있다 )
function scanRows(lotIdx) {
    const rows = [];
    for (const m of materials.value) for (const g of m.groups) for (const l of g.lots) if (l.lot_idx === lotIdx) rows.push({ m, l });
    return rows;
}
async function scanOne(no) {
    const woIdx = props.woIdx;
    try {
        let r = scanCache.get(no);
        if (!r) {
            r = (await window.axios.post(route(props.routes.scan), { work_order_idx: woIdx, barcode_no: no, reason: extra.reason?.trim() || null })).data;
            if (woIdx !== props.woIdx) return;   // 그 사이 다른 지시로 바꿨다
            scanCache.set(no, r);
            if (r.added) {
                await reloadKeeping();
                emit('changed');
            }
        }
        let rows = scanRows(r.lot_idx);
        if (!rows.length) {   // 화면이 낡았다 ( 그 사이 입고 등 ) — 한 번 다시 불러와 본다
            await reloadKeeping();
            rows = scanRows(r.lot_idx);
        }
        if (!rows.length) return scanSay(`${r.lot_no} : 이 창에서 낼 수 있는 재고가 없습니다.`, true);
        const hit = rows.find(x => num(x.l.fill) + 1 <= x.l.qty + 1e-9);
        if (!hit) return scanSay(`${r.lot_no} : 잔고( ${fmtNum(rows.reduce((s, x) => s + x.l.qty, 0))} )를 다 채웠습니다.`, true);
        bumpScan(hit.m, hit.l);
        scanSay(`${r.item_code} · ${r.lot_no} → ${fmtNum(num(hit.l.fill))}${r.added ? ' ( BOM 외 자재로 추가 )' : ''}`);
        r.added = false;   // 추가 알림은 처음 한 번만
    } catch (e) {
        scanSay(e.response?.status === 422 ? errorsOf(e) : `${no} : ${errorsOf(e)}`, true);
    }
}
function bumpScan(m, l) {
    l.fill     = String(+(num(l.fill) + 1).toFixed(6));
    l._scanned = true;
    l._hit     = true;
    setTimeout(() => { l._hit = false; }, 700);
    nextTick(() => document.querySelector(`[data-scan-row="${m.work_order_material_idx}:${l.stock_balance_idx}"]`)?.scrollIntoView({ block: 'nearest' }));
}
// 채운 수량을 남기고 다시 불러온다
async function reloadKeeping() {
    const saved = keepFills();
    await loadPlan();
    restoreFills(saved);
}
async function addExtra(it) {
    if (!it || extra.busy) return;
    extra.busy = true;
    errorText.value = '';
    const saved = keepFills();
    try {
        await window.axios.post(route(props.routes.extraAdd), { work_order_idx: props.woIdx, item_idx: it.item_idx, reason: extra.reason?.trim() || null });
        extra.reason = '';
        await loadPlan();
        restoreFills(saved);
        emit('flash', `${it.item_code} 를 BOM 외 자재로 더했습니다. 수량을 넣고 [출고] 하세요.`, false);
        emit('changed');
    } catch (e) {
        errorText.value = errorsOf(e);
    } finally {
        extra.item_idx = '';
        extra.busy = false;
    }
}
async function removeExtra(m) {
    if (extra.busy || !props.routes.extraRemove) return;
    extra.busy = true;
    const saved = keepFills();
    try {
        await window.axios.post(route(props.routes.extraRemove), { work_order_idx: props.woIdx, work_order_material_idx: m.work_order_material_idx });
        await loadPlan();
        restoreFills(saved);
        emit('changed');
    } catch (e) {
        errorText.value = errorsOf(e);
    } finally {
        extra.busy = false;
    }
}

const planLatest = useLatest();   // 지시를 빠르게 바꿀 때 앞 지시의 늦은 응답이 화면을 덮지 않게 ( 덮이면 앞 지시 자재를 뒤 지시로 출고했다 )
async function loadPlan() {
    const isLatest = planLatest.begin();
    planLoading.value = true;
    errorText.value = '';
    try {
        const [{ data }, hist] = await Promise.all([
            window.axios.get(route(props.routes.plan, props.woIdx)),
            window.axios.get(route(props.routes.history, props.woIdx)),
        ]);
        if (!isLatest()) return;
        order.value     = data.order;
        extraAllowed.value = data.extra_allowed === true;
        scanAllowed.value  = data.scan_allowed === true;
        const scanOn = inputMode.value === 'scan';   // 바코드 스캔 방식 : 자동 채움 없이 빈 칸에서
        if (!['WAIT', 'RUN'].includes(data.order.status)) mode.value = 'return';
        materials.value = (data.materials ?? []).map(m => reactive({
            ...m,
            // 대체 카드는 낸 적이 있을 때만 펼친다 ( 자동 채움 없음 )
            _open: m.is_extra ? true : (m.substitute_of_idx ? m.issued_qty > 0 : (m.need_qty > 0 || !m.issued_qty)),
            groups: m.groups.map(g => ({ ...g, _trace: false, lots: g.lots.map(l => ({ ...l, fill: l.fill && !scanOn ? String(l.fill) : '', _scanned: false, _hit: false })) })),
        }));
        if (props.preferBalanceIdx) preferLot(props.preferBalanceIdx);
        history.value   = hist.data.rows ?? [];
        checked.value   = [];
        form.occurred_at = data.now;
    } catch (e) {
        if (!isLatest()) return;
        order.value = null;
        emit('flash', e.response?.status === 403 ? '권한이 없습니다.' : '출고 정보를 불러오지 못했습니다.', true);
    } finally {
        if (isLatest()) planLoading.value = false;
    }
}

// 고른 LOT 을 먼저 : 그 묶음의 채운 합은 그대로 두고, 그 LOT → 나머지( 원래 순서 ) 로 다시 나눈다
function preferLot(balanceIdx) {
    for (const m of materials.value) {
        for (const g of m.groups) {
            const first = g.lots.find(l => l.stock_balance_idx === balanceIdx);
            if (!first) continue;
            let left = g.lots.reduce((s, l) => s + num(l.fill), 0);
            for (const l of [first, ...g.lots.filter(x => x !== first)]) {
                const take = Math.min(l.qty, left);
                l.fill = take > 1e-9 ? String(+take.toFixed(6)) : '';
                left -= take;
            }
            m._open = true;
        }
    }
}

function errorsOf(e) {
    const s = e.response?.status;
    return s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' / ') : s === 403 ? '권한이 없습니다.' : '처리하지 못했습니다.';
}

async function save() {
    const lines = [];
    for (const m of materials.value) {
        for (const g of m.groups) {
            for (const l of g.lots) {
                if (num(l.fill) > 0) lines.push({ work_order_material_idx: m.work_order_material_idx, stock_balance_idx: l.stock_balance_idx, qty: String(l.fill) });
            }
        }
    }
    if (!lines.length || planLoading.value || order.value?.work_order_idx !== props.woIdx) return;   // 화면의 지시 = 고른 지시일 때만
    if (materials.value.some(m => m.groups.some(g => g.lots.some(over)))) {
        errorText.value = 'LOT 잔고보다 많이 낼 수 없습니다. ( 빨간 칸 )';
        return;
    }
    saving.value = true;
    errorText.value = '';
    try {
        const { data } = await window.axios.post(route(props.routes.save), {
            work_order_idx: props.woIdx, occurred_at: form.occurred_at, remark: form.remark || null, lines,
        });
        const base = `${data.txn_no} 출고했습니다.`;
        emit('flash', data.warnings?.length ? `${base} ⚠ ${data.warnings.join(' / ')}` : base, false);
        form.remark = '';
        resetScan();
        await loadPlan();
        emit('changed');
        focusScan();
    } catch (e) {
        errorText.value = errorsOf(e);
    } finally {
        saving.value = false;
    }
}

async function cancelChecked() {
    const target = history.value.filter(r => checked.value.includes(r.stock_txn_line_idx));
    if (!target.length) return;
    const names = target.slice(0, 3).map(r => `${r.item_code} ${r.lot_no ?? ''}`).join(', ') + (target.length > 3 ? ` 외 ${target.length - 3}줄` : '');
    const r = await showAlert('출고 취소', `${target.length}줄( ${names} )의 출고를 취소합니다.\n자재는 출고한 창고로 돌아갑니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true;
    try {
        const { data } = await window.axios.post(route(props.routes.cancel), {
            work_order_idx: props.woIdx, stock_txn_line_idxs: target.map(x => x.stock_txn_line_idx),
        });
        emit('flash', `${data.canceled}줄의 출고를 취소했습니다.`, false);
        await loadPlan();
        emit('changed');
    } catch (e) {
        emit('flash', errorsOf(e), true);
    } finally {
        saving.value = false;
    }
}

watch(() => props.woIdx, async () => { order.value = null; form.remark = ''; resetScan(); await loadPlan(); focusScan(); }, { immediate: true });

// 스캔 칸 포커스 지키기 : 바코드 스캔 방식 + 출고 탭일 때만
watch(() => inputMode.value === 'scan' && mode.value === 'issue', guardScanFocus, { immediate: true, flush: 'post' });
onBeforeUnmount(() => guardScanFocus(false));

defineExpose({ reload: loadPlan });
</script>
