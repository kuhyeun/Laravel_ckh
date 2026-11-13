<!-- 공정실적 ( PRD-06 · 생산 3단계 최소판 ) / 생산관리 > 공정실적관리 — docs/screens/50-생산.md "3단계"
     - 왼쪽 : 품목 줄( 대기 · 진행 기본 ) — 검색어 · 상태. ?wo=번호 로 들어오면 그 줄을 연다
     - 오른쪽 : 공정마다 기준 · 실적 · 상태 → 공정을 고르면 아래에 실적 줄 + [공정 완료] · 검사 · 시스템 공정은 [완료]만
       · 실적을 쌓는 공정을 고르면 입력 칸이 바로 열린다( [실적 입력] 버튼 없음 · 11-10 ) — 저장하면 칸을 비워 다음 실적을 받는다
       · 뒤 공정은 앞 수량 공정 실적까지만 ( 서버가 막음 ) · 포장도 수량 공정
       · 마지막 공정이 수량 공정이면 실적마다 그 수량만큼 바로 제품 입고( 실적 입력 때 창고 ) — 아니면 줄 완료 때 남은 수량 입고
     - 원자재출고 공정 · 자재의 [출고 · 반납] : 모달로 자재출고 · 반납( MaterialIssuePanel — 11-12 ) — 완료할 때 덜 나간 자재가 있으면 한 번 묻는다 · 완료된 줄은 반납만
     - 외주 공정 [외주 출고] · [외주 입고] : 외주관리 화면으로 가지 않고 그 창( SubconIssueForm · SubconReceiptForm )을 모달로 ( 외주 메뉴 권한이 있을 때 · 11-12 )
     - 되돌리기 : 실적 취소 · 완료 취소 · 줄 완료 취소 · 출고 취소 ( can_delete )
     - 검사 공정 ( 품질 모듈이 켜져 있으면 ) : [검사] = 공정검사 창( ProcessInspectionForm — 6002 와 같은 창 ) · 공정 수량 = 합격 → 뒤 공정 기준 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">

        <SearchBar
            v-model="search"
            :fields="SEARCH_FIELDS"
            :loading="loading"
            :blank="blankFilters"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.status" :options="STATUS_FILTER" class="w-32" />
        </SearchBar>

        <div class="flex-1 min-h-0 flex gap-3">
            <!-- 품목 줄 -->
            <div class="flex-[1] min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">품목 줄 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                    <div class="flex items-center gap-1.5">
                        <GridSettingButton :grid="mainGrid" />
                        <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="공정실적_품목줄" />
                    </div>
                </div>
                <div class="flex-1 min-h-0 flex flex-col">
                    <DataGrid
                        ref="mainGrid"
                        :loading="loading"
                        setting-key="main"
                        class="flex-1"
                        :data="rows"
                        :columns="columns"
                        :pagination="paging"
                        empty-text="실적을 넣을 품목 줄이 없습니다."
                        active-field="work_order_idx"
                        :active-key="activeWo"
                        @row-click="row => select(row.work_order_idx)"
                        @page="onPage"
                        @sort="onSort"
                    />
                </div>
            </div>

            <!-- 공정 -->
            <div class="flex-[2] min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div v-if="!line" class="flex-1 flex items-center justify-center text-sm text-gray-400">
                    {{ detailLoading ? '불러오는 중…' : '왼쪽에서 품목 줄을 고르세요.' }}
                </div>
                <template v-else>
                    <div class="px-3 py-2 bg-gray-50/70 shrink-0 flex items-center gap-3 flex-wrap">
                        <span class="text-sm font-semibold">{{ line.wo_no }}</span>
                        <span class="text-sm text-gray-600">{{ line.item_label }} · 지시 {{ fmtNum(line.target_qty) }} {{ line.spec_unit }}</span>
                        <span v-if="line.lot_no" class="text-xs text-gray-400">LOT {{ line.lot_no }}</span>
                        <span v-if="line.due_date" class="text-xs text-gray-400">기한 {{ line.due_date }}</span>
                        <span v-if="line.received_qty" class="text-xs text-blue-700">입고 {{ fmtNum(line.received_qty) }} {{ line.spec_unit }}</span>
                        <span class="text-sm font-semibold" :style="{ color: woStatusColor(line.status) }">{{ woStatusLabel(line.status) }}</span>
                        <div class="flex-1"></div>
                        <!-- 모든 공정 완료 ( 11-12 ) : 마지막 수량 공정 실적이 지시 수량을 채웠으면 남은 공정을 한 번에 — 입고할 수량이 남으면 창고 -->
                        <template v-if="canWrite && line.complete_all?.can">
                            <WarehouseSelect v-if="line.complete_all.needs_warehouse" v-model="allWarehouse" :options="warehouses" class="w-40" placeholder="입고 창고" />
                            <BaseButton size="sm" variant="primary" :disabled="busy" title="실적이 지시 수량을 채운 줄의 남은 공정을 순서대로 한 번에 완료합니다" @click="completeAll">모든 공정 완료 ( {{ line.complete_all.open }} )</BaseButton>
                        </template>
                        <BaseButton v-if="canDelete && line.status === 'DONE'" size="sm" variant="danger" :disabled="busy" @click="cancelLineDone">완료 취소 ( 제품 입고 되돌림 )</BaseButton>
                    </div>

                    <div class="flex-1 min-h-0 overflow-y-auto p-3 space-y-4">
                        <p v-if="errorText" class="text-xs text-red-500 whitespace-pre-line">{{ errorText }}</p>

                        <!-- 공정 표 — 머리줄 오른쪽 : 원자재출고 공정이 없는 줄의 자재 [출고] · [반납] ( 있으면 그 공정 줄에 · 11-12 ) -->
                        <div>
                        <div class="flex items-center gap-2 mb-1.5 min-h-[28px]">
                            <span class="text-xs font-semibold text-gray-500">공정</span>
                            <span class="text-xs text-gray-400">{{ line.ops.length }}</span>
                            <div class="flex-1"></div>
                            <template v-if="canWrite && line.materials.length && !hasRmOut">
                                <span class="text-xs text-gray-400">자재</span>
                                <BaseButton v-if="line.status !== 'DONE'" size="sm" variant="secondary" @click="openIssue('issue')">출고</BaseButton>
                                <BaseButton v-if="hasIssued" size="sm" variant="secondary" @click="openIssue('return')">반납</BaseButton>
                            </template>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[832px] table-fixed text-sm border">
                                <colgroup>
                                    <col class="w-12"><col>
                                    <col class="w-20"><col class="w-20">
                                    <col class="w-16"><col class="w-48"><col class="w-52">
                                </colgroup>
                                <thead class="bg-gray-50 text-gray-500 text-xs">
                                    <tr>
                                        <th class="px-2 py-1.5">OP</th><th class="px-2 py-1.5 text-left">공정</th>
                                        <th class="px-2 py-1.5 text-right">기준</th><th class="px-2 py-1.5 text-right">실적</th>
                                        <th class="px-2 py-1.5">상태</th><th class="px-2 py-1.5 text-left">비고</th><th class="px-2 py-1.5"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="o in line.ops" :key="o.work_order_op_idx" class="border-t cursor-pointer"
                                        :class="selectedOp?.work_order_op_idx === o.work_order_op_idx ? 'bg-blue-50' : 'hover:bg-gray-50'"
                                        @click="pickOp(o)">
                                        <td class="px-2 py-1.5 text-center text-gray-500">{{ String(o.seq).padStart(3, '0') }}</td>
                                        <td class="px-2 py-1.5 truncate" :title="o.name">{{ o.name }} <span class="text-xs text-gray-400">{{ o.is_qty ? '' : (o.is_inspection && line.quality_on ? '( 검사 )' : '( 완료만 )') }}</span></td>
                                        <td class="px-2 py-1.5 text-right text-gray-500">{{ o.base_qty === null ? '—' : fmtNum(o.base_qty) }}</td>
                                        <td class="px-2 py-1.5 text-right font-semibold">{{ o.is_qty || (o.is_inspection && o.status === 'DONE') ? fmtNum(o.qty) : '—' }}</td>
                                        <td class="px-2 py-1.5 text-center font-semibold" :style="{ color: woStatusColor(o.status) }">{{ woStatusLabel(o.status) }}</td>
                                        <td class="px-2 py-1.5 text-xs">
                                            <span v-if="o.short_qty" class="text-amber-700">미달 {{ fmtNum(o.short_qty) }}<template v-if="o.short_reason"> · {{ o.short_reason }}</template></span>
                                            <span v-else-if="o.block" class="text-gray-400">{{ o.block }}</span>
                                        </td>
                                        <td class="px-2 py-1 text-right whitespace-nowrap" @click.stop>
                                            <!-- 검사 공정 : 검사 창 ( 완료 뒤에도 기록을 본다 · 취소도 그 창에서 ) -->
                                            <!-- 원자재출고 공정 : [출고]( 줄이 끝나기 전 ) · [반납]( 출고된 자재가 있으면 — 완료한 뒤에도 ) → 모달 ( 11-12 — 아래 자재 칸의 단추는 없앰 ) -->
                                            <template v-if="canWrite && o.is_rm_out && line.materials.length">
                                                <BaseButton v-if="line.status !== 'DONE'" size="sm" variant="secondary" @click="openIssue('issue')">출고</BaseButton>
                                                <BaseButton v-if="hasIssued" size="sm" variant="secondary" class="ml-1 mr-1" @click="openIssue('return')">반납</BaseButton>
                                            </template>
                                            <BaseButton v-if="o.is_inspection && line.quality_on" size="sm" :variant="o.status === 'DONE' ? 'secondary' : 'primary'" @click="openInspection(o)">검사</BaseButton>
                                            <template v-else-if="canWrite && o.status !== 'DONE' && line.status !== 'DONE'">
                                                <!-- 외주 공정 : 실적은 외주 입고로만 → 그 창을 모달로 ( 외주 출고 · 입고 메뉴 권한이 있을 때만 ) -->
                                                <template v-if="o.is_subcon">
                                                    <BaseButton v-if="subconUrls.issue" size="sm" variant="secondary" :disabled="busy" @click="openSubconIssue(o)">외주 출고</BaseButton>
                                                    <BaseButton v-if="subconUrls.receipt" size="sm" variant="secondary" class="ml-1" :disabled="busy" @click="openSubconReceipt(o)">외주 입고</BaseButton>
                                                    <span v-if="!subconUrls.issue && !subconUrls.receipt" class="text-xs text-gray-400" title="외주관리 > 외주 출고 · 입고로 처리합니다">외주관리에서 처리</span>
                                                </template>
                                                <BaseButton size="sm" variant="primary" class="ml-1" :disabled="!o.is_qty && !!o.block" @click="openPanel(o, 'done')">{{ o.is_qty ? '공정 완료' : '완료' }}</BaseButton>
                                            </template>
                                            <BaseButton v-if="canDelete && o.status === 'DONE' && line.status !== 'DONE' && !(o.is_inspection && line.quality_on)" size="sm" variant="secondary" :disabled="busy" @click="cancelComplete(o)">완료 취소</BaseButton>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        </div>

                        <!-- 선택한 공정 : 입력 · 완료 · 실적 줄 -->
                        <section v-if="selectedOp" class="border rounded">
                            <div class="px-3 py-2 bg-gray-50/70 text-sm font-semibold text-gray-600 flex items-center gap-2">
                                {{ String(selectedOp.seq).padStart(3, '0') }} {{ selectedOp.name }}
                                <span v-if="selectedOp.started_at" class="text-xs font-normal text-gray-400">시작 {{ selectedOp.started_at }}</span>
                                <span v-if="selectedOp.done_at" class="text-xs font-normal text-gray-400">· 완료 {{ selectedOp.done_at }}</span>
                            </div>

                            <!-- 실적 입력 -->
                            <div v-if="panel === 'run'" class="px-3 py-3 border-b bg-blue-50/40 flex items-end gap-3 flex-wrap">
                                <p class="w-full -mb-1 text-xs font-semibold text-blue-700">새 실적 입력</p>
                                <!-- 순서 : 시작 · 종료 · 설비 · 작업자 · 수량 · ( 입고 창고 ) · 비고 ( 11-05 · 시작 / 종료 11-12 ) -->
                                <div>
                                    <label class="lbl">시작 시간</label>
                                    <BaseDatePicker v-model="runForm.started_at" mode="datetime" class="w-48" />
                                </div>
                                <div>
                                    <label class="lbl">종료 시간 <span class="text-red-500">*</span></label>
                                    <BaseDatePicker v-model="runForm.worked_at" mode="datetime" class="w-48" />
                                </div>
                                <div>
                                    <label class="lbl">설비</label>
                                    <BaseSelect v-model="runForm.equipment_idx" :options="equipOptions" class="w-44" />
                                </div>
                                <div>
                                    <label class="lbl">작업자 <span class="text-red-500">*</span></label>
                                    <BaseSelect v-model="runForm.operator_idx" :options="userOptions" placeholder="작업자 선택" class="w-36" />
                                </div>
                                <div>
                                    <label class="lbl">수량 <span class="text-red-500">*</span></label>
                                    <BaseInput v-model="runForm.qty" type="number" min="0" class="w-28 text-right" />
                                </div>
                                <div v-if="selectedOp.receives_on_run">
                                    <label class="lbl">입고 창고 <span class="text-red-500">*</span></label>
                                    <WarehouseSelect v-model="runForm.warehouse_idx" :options="warehouses" class="w-44" placeholder="창고" />
                                </div>
                                <div>
                                    <label class="lbl">비고</label>
                                    <BaseInput v-model="runForm.remark" class="w-80" maxlength="200" />
                                </div>
                                <div class="ml-auto flex gap-1">
                                    <BaseButton size="sm" variant="secondary" :disabled="busy" @click="resetRunForm(selectedOp)">지우기</BaseButton>
                                    <BaseButton size="sm" variant="primary" :disabled="busy" @click="addRun">저장</BaseButton>
                                </div>
                                <p class="w-full text-xs text-gray-500">
                                    <template v-if="selectedOp.is_first_qty">지시 수량 {{ fmtNum(selectedOp.base_qty) }} · 지금 {{ fmtNum(selectedOp.qty) }}</template>
                                    <template v-else>앞 공정 실적 {{ fmtNum(selectedOp.base_qty) }} 까지 · 더 넣을 수 있는 수량 <b>{{ fmtNum(Math.max(0, selectedOp.base_qty - selectedOp.qty)) }}</b></template>
                                    <template v-if="selectedOp.receives_on_run"> · <span class="text-blue-700">마지막 공정이라 저장하면 이 수량만큼 바로 제품을 입고합니다</span></template>
                                </p>
                                <!-- 투입 LOT ( 공정 LOT 추적 지시 · 실적LOT-설계 §5 ) — 후보가 있으면 필수 -->
                                <!-- 투입 LOT : 수동 = 고른다 / 자동( 설정 'LOT 투입 방식' · 11-12 ) = 저장할 때 먼저 들어온 LOT 부터 실적 수량만큼 자동 연결 -->
                                <p v-if="runLot && line.lot_input_auto" class="w-full pt-2 border-t border-blue-100 text-xs text-blue-700">
                                    투입 LOT 는 자동으로 연결됩니다 — 먼저 들어온 LOT 부터( 자재 = 입고일순으로 지금 쓰고 있을 LOT · 앞 공정 = 작업 일시순으로 실적 수량만큼 ). 저장한 뒤 아래 실적 줄에서 확인 · [+ 연결] 로 더하거나 해제할 수 있습니다.
                                </p>
                                <RunInputPicker v-else-if="runLot" ref="runPicker" class="w-full pt-2 border-t border-blue-100"
                                    :op-idx="selectedOp.work_order_op_idx" v-model="runForm.inputs" required />
                            </div>

                            <!-- 완료 -->
                            <div v-if="panel === 'done'" class="px-3 py-3 border-b bg-blue-50/40 flex items-end gap-3 flex-wrap">
                                <p class="w-full text-sm">
                                    <template v-if="selectedOp.is_qty">실적 {{ fmtNum(selectedOp.qty) }} / 기준 {{ fmtNum(selectedOp.base_qty) }} 으로 완료합니다.</template>
                                    <template v-else>{{ selectedOp.name }} 을 완료합니다.</template>
                                    <span v-if="selectedOp.is_rm_out" class="ml-1 text-gray-500">덜 나간 자재가 있으면 한 번 더 묻습니다.</span>
                                    <span v-if="needReason" class="ml-1 text-amber-700">{{ fmtNum(selectedOp.base_qty - selectedOp.qty) }} 미달 — 사유를 고르세요.</span>
                                    <span v-if="selectedOp.needs_warehouse" class="ml-1 text-blue-700">마지막 공정이라 남은 수량을 제품 입고하고 줄을 완료합니다.</span>
                                    <span v-else-if="selectedOp.is_last_open" class="ml-1 text-blue-700">마지막 공정이라 줄을 완료합니다. ( 입고는 실적 때 했습니다 )</span>
                                </p>
                                <div v-if="needReason">
                                    <label class="lbl">미달 사유 <span class="text-red-500">*</span></label>
                                    <BaseSelect v-model="doneForm.short_reason" :options="reasonOptions" class="w-40" placeholder="사유" />
                                </div>
                                <div v-if="selectedOp.needs_warehouse">
                                    <label class="lbl">입고 창고 <span class="text-red-500">*</span></label>
                                    <WarehouseSelect v-model="doneForm.warehouse_idx" :options="warehouses" class="w-44" placeholder="창고" />
                                </div>
                                <div class="flex gap-1">
                                    <BaseButton size="sm" variant="secondary" @click="closeDone">닫기</BaseButton>
                                    <BaseButton size="sm" variant="primary" :disabled="busy" @click="complete()">완료</BaseButton>
                                </div>
                            </div>

                            <!-- 실적 줄 -->
                            <div v-if="selectedOp.is_qty" class="overflow-x-auto">
                                <table class="w-full min-w-[840px] table-fixed text-xs">
                                    <colgroup>
                                        <col class="w-56"><col v-if="runLot" class="w-56"><col class="w-40"><col class="w-48"><col class="w-28"><col><col class="w-14">
                                    </colgroup>
                                    <thead class="text-gray-500"><tr><th class="px-3 py-1.5 text-left">작업 시간</th><th v-if="runLot" class="px-2 py-1.5 text-left">실적 LOT</th><th class="px-2 py-1.5 text-left">설비</th><th class="px-2 py-1.5 text-left">작업자</th><th class="px-2 py-1.5 text-right">수량</th><th class="px-2 py-1.5 text-left">비고</th><th></th></tr></thead>
                                    <tbody>
                                        <tr v-if="!selectedOp.runs.length"><td :colspan="runLot ? 7 : 6" class="px-3 py-3 text-center text-gray-400">실적이 없습니다.</td></tr>
                                        <template v-for="r in selectedOp.runs" :key="r.run_idx">
                                        <tr class="border-t">
                                            <td class="px-3 py-1.5 whitespace-nowrap">{{ runTimeText(r.started_at, r.worked_at) }}</td>
                                            <td v-if="runLot" class="px-2 py-1.5 align-top">
                                                <div class="flex items-center gap-1">
                                                    <span class="truncate font-medium text-gray-700" :title="r.lot_no ?? ''">{{ r.lot_no ?? '—' }}</span>
                                                    <button v-if="canWrite && r.lot_no" type="button" class="shrink-0 text-[11px] text-blue-600 hover:underline" :disabled="busy" @click="openAddInput(r)">+ 연결</button>
                                                </div>
                                                <!-- 투입 LOT ( ← 자재 · 앞 공정 ) · ✕ = 연결 해제 -->
                                                <div v-if="r.inputs?.length" class="mt-0.5 flex flex-wrap gap-1">
                                                    <span v-for="x in r.inputs" :key="x.run_input_idx" class="inline-flex items-center gap-1 px-1 rounded text-[11px]"
                                                        :class="x.input_type === 'MATERIAL' ? 'bg-amber-50 text-amber-800' : 'bg-gray-100 text-gray-600'"
                                                        :title="`${x.input_type === 'MATERIAL' ? '자재' : '앞 공정'} LOT · 연결 ${x.creator ?? ''}`">
                                                        ← {{ x.lot_no }}<template v-if="x.qty"> {{ fmtNum(x.qty) }}</template>
                                                        <button v-if="canWrite" type="button" class="text-gray-400 hover:text-red-500" :disabled="busy" title="연결 해제" @click="cancelInput(x)">✕</button>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-2 py-1.5 truncate" :title="r.equipment_name ?? ''">{{ r.equipment_name ?? '—' }}</td>
                                            <td class="px-2 py-1.5">{{ r.operator }} <span v-if="r.is_proxy" class="text-gray-400">( 입력 : {{ r.creator }} )</span></td>
                                            <td class="px-2 py-1.5 text-right font-semibold">{{ fmtNum(r.qty) }}<span v-if="r.received" class="ml-1 px-1 rounded bg-blue-50 text-blue-700 font-normal">입고</span></td>
                                            <td class="px-2 py-1.5 text-gray-500 truncate" :title="r.remark">{{ r.remark }}</td>
                                            <td class="px-2 py-1 text-right"><button v-if="canDelete && selectedOp.status !== 'DONE'" type="button" class="text-gray-400 hover:text-red-500" :disabled="busy" @click="cancelRun(r)">취소</button></td>
                                        </tr>
                                        <tr v-if="runLot && addingRun === r.run_idx" class="bg-blue-50/40">
                                            <td :colspan="7" class="px-3 py-2">
                                                <p class="mb-1 text-xs text-gray-600"><b>{{ r.lot_no }}</b> 에 투입 LOT 더하기 ( 나중에 출고한 자재 · 빠뜨린 앞 공정 LOT )</p>
                                                <!-- 이미 이 실적에 연결된 LOT 는 후보에서 뺀다 ( 11-12 · 서버도 막는다 ) -->
                                                <RunInputPicker :op-idx="selectedOp.work_order_op_idx" v-model="addInputs" :exclude="r.inputs.map(x => `${x.input_type}:${x.lot_idx}`)" />
                                                <div class="mt-1.5 flex justify-end gap-1">
                                                    <BaseButton size="sm" variant="secondary" :disabled="busy" @click="addingRun = null">닫기</BaseButton>
                                                    <BaseButton size="sm" variant="primary" :disabled="busy || !addInputs.length" @click="saveAddInputs(r)">연결 추가</BaseButton>
                                                </div>
                                            </td>
                                        </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <!-- 자재 — 원자재출고 공정을 골랐을 때만 ( 11-12 · 원자재출고 공정이 없는 줄은 위 공정 머리줄의 [출고] · [반납] 으로 ) -->
                        <section v-if="selectedOp?.is_rm_out">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="text-xs font-semibold text-gray-500">자재</span>
                                <span class="text-xs text-gray-400">( 공정이 시작되면 보유분을 사용처리합니다 · 출고 · 반납은 위 원자재출고 공정의 [출고] · [반납] )</span>
                            </div>
                            <table class="w-full text-xs border">
                                <thead class="bg-gray-50 text-gray-500"><tr><th class="px-2 py-1.5 text-left">자재</th><th v-if="SHOW_OP" class="px-2 py-1.5 text-left">투입 공정</th><th class="px-2 py-1.5 text-right">출고</th><th class="px-2 py-1.5 text-right">사용</th><th class="px-2 py-1.5 text-right">반납</th><th class="px-2 py-1.5 text-right">보유</th></tr></thead>
                                <tbody>
                                    <tr v-if="!line.materials.length"><td :colspan="SHOW_OP ? 6 : 5" class="px-2 py-3 text-center text-gray-400">자재가 없습니다.</td></tr>
                                    <tr v-for="(m, i) in line.materials" :key="i" class="border-t">
                                        <td class="px-2 py-1">{{ m.item_label }}</td>
                                        <td v-if="SHOW_OP" class="px-2 py-1 text-gray-500">{{ m.input_op }}</td>
                                        <td class="px-2 py-1 text-right" :class="m.issued ? '' : 'text-gray-400'">{{ m.issued ? fmtNum(m.issued) : '미출고' }}</td>
                                        <td class="px-2 py-1 text-right text-gray-500">{{ fmtNum(m.consumed) }}</td>
                                        <td class="px-2 py-1 text-right text-gray-500">{{ fmtNum(m.returned) }}</td>
                                        <td class="px-2 py-1 text-right font-semibold">{{ fmtNum(m.held) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { SHOW_INPUT_OP as SHOW_OP } from '@core/Utils/features';
import { ref, reactive, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { showAlert } from '@core/Utils/message';
import { woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';
import MaterialIssuePanel from '@core/Components/Production/MaterialIssuePanel.vue';
import SubconIssueForm from '@core/Components/Production/SubconIssueForm.vue';
import SubconReceiptForm from '@core/Components/Production/SubconReceiptForm.vue';
import SubconIssuePick from '@core/Components/Production/SubconIssuePick.vue';
import ProcessInspectionForm from '@core/Components/Quality/ProcessInspectionForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';
import WarehouseSelect from '@core/Components/Common/WarehouseSelect.vue';
import RunInputPicker from '@core/Components/Production/RunInputPicker.vue';
import { fmtNum, runTimeText } from '@core/Utils/format';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const STATUS_FILTER = [
    { value: 'OPEN', label: '대기 · 진행' },
    { value: 'WAIT', label: '대기' },
    { value: 'RUN',  label: '진행' },
    { value: 'DONE', label: '완료' },
];
// 검색 조건 ( 서버 OpResultController applyKeyword 의 키 — 통합검색은 SearchBar 가 붙인다 )
const SEARCH_FIELDS = [
    { value: 'wo_no',     label: '줄 번호' },
    { value: 'item_code', label: '품번' },
    { value: 'item_name', label: '품명' },
    { value: 'lot_no',    label: 'LOT' },
];
const blankFilters = () => ({ status: 'OPEN' });   // 거르기 기본값 — 처음 값 · [초기화] 같이 ( 스타일 가이드 §3 )
const search = reactive({ keyword: '', ...blankFilters() });

const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };

const columns = [
    { header: '줄 번호', name: 'wo_no', width: 150, sortable: true, className: 'cursor-pointer' },
    { header: '품목', name: 'item_label', minWidth: 120, className: 'cursor-pointer', escapeHTML: true },
    { header: '규격', name: 'item_spec', width: 110, className: 'cursor-pointer', escapeHTML: true },
    { header: '재질', name: 'item_material', width: 90, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'target_qty', width: 70, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => fmtNum(row.target_qty) },
    { header: '공정', name: 'ops_count', width: 50, align: 'center', className: 'cursor-pointer', formatter: ({ row }) => `${row.done_ops_count}/${row.ops_count}` },
    { header: '상태', name: 'status', width: 50, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => `<span style="color:${woStatusColor(value)};font-weight:600">${woStatusLabel(value)}</span>` },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

const line          = ref(null);
const activeWo      = ref(null);   // 왼쪽 목록에 칠할 줄 — 누르는 즉시 칠하고, 못 불러오면 뺀다
const selectedIdx   = ref(null);
const selectedOp    = computed(() => line.value?.ops.find(o => o.work_order_op_idx === selectedIdx.value) ?? null);
const panel         = ref('');      // '' | 'run' | 'done'
const runLot        = computed(() => line.value?.lot_mode === 'RUN');   // 실적마다 LOT ( 실적LOT-설계 ) — 실적 줄에 LOT 열
const detailLoading = ref(false);
const busy          = ref(false);
const errorText     = ref('');

// 출고 패널은 이 화면 권한으로 부르는 주소 ( routes/production.php opResult.issue* )
const ISSUE_ROUTES = {
    plan: 'production.opResult.issuePlan', history: 'production.opResult.issueHistory',
    save: 'production.opResult.issueSave', cancel: 'production.opResult.issueCancel',
    returnPlan: 'production.opResult.returnPlan', returnSave: 'production.opResult.returnSave', returnCancel: 'production.opResult.returnCancel',
    leftoverSave: 'production.opResult.leftoverSave', leftoverCancel: 'production.opResult.leftoverCancel',   // 잔재 입고 ( 11-12 )
};

// ---- 선택지 ----
const users      = ref([]);
const warehouses = ref([]);
const equipments = ref([]);   // 사용 중 설비 전부 ( formData )
const reasons    = ref([]);
const me         = ref(null);
// 외주 출고 · 입고 화면 주소 ( 권한 있을 때만 — formData ) · 이 지시번호로 걸러서 연다
const subconUrls = reactive({ issue: null, receipt: null });
// 지금( − 시간 ) — 'YYYY-MM-DDTHH:mm' ( 실적 기본 : 종료 = 지금 · 시작 = 2시간 전 — 11-11 회의 )
const localNow = (minusHours = 0) => { const d = new Date(Date.now() - minusHours * 3600000); const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };
const userOptions      = computed(() => users.value.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id })));
const reasonOptions    = computed(() => reasons.value.map(r => ({ value: r.system_code_key, label: r.system_code_name })));
// 설비 : 이 공정의 후보 먼저 · 그 밖의 사용 중 설비 ( 후보가 아니어도 고를 수 있다 — 11-05 )
const equipOptions     = computed(() => {
    const cand = selectedOp.value?.equipments ?? [];
    const ids  = new Set(cand.map(e => Number(e.equipment_idx)));
    return [{ value: '', label: '( 미지정 )' },
        ...cand.map(e => ({ value: e.equipment_idx, label: e.equipment_name })),
        ...equipments.value.filter(e => !ids.has(Number(e.equipment_idx))).map(e => ({ value: e.equipment_idx, label: cand.length ? `${e.equipment_name} ( 다른 공정 )` : e.equipment_name }))];
});

const runForm  = reactive({ qty: '', equipment_idx: '', operator_idx: '', started_at: '', worked_at: '', remark: '', warehouse_idx: '', inputs: [] });
const runPicker = ref(null);        // 투입 LOT 고르기 ( 저장 뒤 후보 다시 — 연결 수량이 바뀐다 )
const addingRun = ref(null);        // [+ 연결] 을 연 실적
const addInputs = ref([]);
const doneForm = reactive({ short_reason: '', warehouse_idx: '' });
const needReason = computed(() => selectedOp.value?.is_qty && num(selectedOp.value.base_qty) - num(selectedOp.value.qty) > 1e-6);

// 입고 창고는 지난번에 고른 창고 ( 이 브라우저 )
const WH_KEY = 'mes.opResult.warehouse';
const lastWarehouse = () => { try { return localStorage.getItem(WH_KEY) ?? ''; } catch (e) { return ''; } };
const defaultWarehouse = () => lastWarehouse() ? Number(lastWarehouse()) : (warehouses.value[0]?.warehouse_idx ?? '');
const rememberWarehouse = (v) => { try { localStorage.setItem(WH_KEY, String(v)); } catch (e) { /* 저장 못 해도 동작 */ } };

const { showFlash } = useToast();   // 화면 알림 = Toast ( style-guide §7 )
const failMsg = (e) => {
    const s = e.response?.status;
    return s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join('\n') : s === 403 ? '권한이 없습니다.' : '처리하지 못했습니다.';
};
const okMsg = (base, warnings = []) => warnings.length ? `${base}\n⚠ ${warnings.join('\n⚠ ')}` : base;

// ---- 목록 ----
async function load() {
    loading.value = true;
    try {
        const p = { ...search, page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
        const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
        const { data } = await window.axios.get(route('production.opResult.list'), { params });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash('품목 줄 목록을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
// 엑셀 : 지금 조건 전체 ( export=1 — 페이지와 상관없이 )
async function exportRows() {
    const p = { ...search, export: 1 };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
    const { data } = await window.axios.get(route('production.opResult.list'), { params });
    return data.rows ?? [];
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

// ---- 상세 ----
async function select(woIdx) {
    activeWo.value = woIdx;
    if (line.value?.work_order_idx !== woIdx) line.value = null;   // 다른 줄이면 앞 줄 상세를 내린다 ( 불러오는 동안 앞 지시에 실적을 넣지 않게 )
    selectedIdx.value = null;
    panel.value = '';
    await loadDetail(woIdx);
}
const detailLatest = useLatest();   // 줄을 빠르게 바꿀 때 앞 줄의 늦은 응답이 상세를 덮지 않게 ( 덮이면 칠한 줄과 다른 지시에 실적이 들어갔다 )
async function loadDetail(woIdx = line.value?.work_order_idx) {
    if (!woIdx) return;
    const isLatest = detailLatest.begin();
    detailLoading.value = true;
    errorText.value = '';
    try {
        const { data } = await window.axios.get(route('production.opResult.show', woIdx));
        if (!isLatest() || (activeWo.value !== null && activeWo.value !== woIdx)) return;   // 그 사이 다른 줄을 골랐다
        line.value = data.line;
        // 고른 공정이 없으면 진행 중인 첫 공정
        let opChanged = false;
        if (!line.value.ops.some(o => o.work_order_op_idx === selectedIdx.value)) {
            selectedIdx.value = (line.value.ops.find(o => o.status !== 'DONE') ?? line.value.ops[0])?.work_order_op_idx ?? null;
            opChanged = true;
        }
        syncPanel(opChanged);
    } catch (e) {
        if (!isLatest()) return;
        line.value = null;
        activeWo.value = null;
        showFlash(e.response?.status === 404 ? '품목 줄을 찾을 수 없습니다.' : '품목 줄을 불러오지 못했습니다.', true);
    } finally {
        if (isLatest()) detailLoading.value = false;
    }
}
async function refresh() {
    await Promise.all([loadDetail(), load()]);
    runPicker.value?.reload();   // 투입 LOT 후보 ( 출고 · 연결 · 실적이 바뀌었을 수 있다 )
}

// ---- 실적 입력 칸 ( 11-10 ) ----
//   [실적 입력] 버튼 없이 : 실적을 쌓는 공정( 수량 공정 · 외주 아님 · 완료 전 · 막힘 없음 · 쓰기 권한 )을 고르면 입력 칸이 바로 열린다
//   저장하면 칸을 비워 새 실적을 받는다 ( 닫지 않음 ) · [공정 완료] 를 누르면 완료 칸으로 바뀌고 [닫기] 면 입력 칸으로 돌아온다
const canRun = (o) => !!o && canWrite.value && o.is_qty && !o.is_subcon && o.status !== 'DONE' && line.value?.status !== 'DONE' && !o.block;

function resetRunForm(o) {
    Object.assign(runForm, { qty: '', equipment_idx: o?.equipment_idx ?? '', operator_idx: me.value ?? '', started_at: localNow(2), worked_at: localNow(), remark: '', warehouse_idx: defaultWarehouse(), inputs: [] });
}

// 고른 공정에 맞춰 칸을 정한다 — opChanged : 다른 공정을 골랐다( 입력 칸을 새로 채운다 ) · 완료 칸은 같은 공정이면 그대로
function syncPanel(opChanged) {
    if (panel.value === 'done' && !opChanged) return;
    if (canRun(selectedOp.value)) {
        if (opChanged || panel.value !== 'run') resetRunForm(selectedOp.value);
        panel.value = 'run';
    } else {
        panel.value = '';
    }
}

function pickOp(o) {
    const changed = selectedIdx.value !== o.work_order_op_idx;
    selectedIdx.value = o.work_order_op_idx;
    if (changed) errorText.value = '';
    syncPanel(changed);
}

function closeDone() {
    panel.value = '';
    syncPanel(false);
}

function openPanel(o, kind) {
    selectedIdx.value = o.work_order_op_idx;
    errorText.value = '';
    if (kind === 'run') {
        resetRunForm(o);
    } else {
        Object.assign(doneForm, { short_reason: '', warehouse_idx: defaultWarehouse() });
    }
    panel.value = kind;
}

// ---- 투입 LOT : 나중에 더하기 · 해제 ( 실적LOT-설계 §5 ) ----
function openAddInput(r) {
    addInputs.value = [];
    addingRun.value = addingRun.value === r.run_idx ? null : r.run_idx;
}
async function saveAddInputs(r) {
    busy.value = true; errorText.value = '';
    try {
        const { data } = await window.axios.post(route('production.opResult.addInputs'), { run_idx: r.run_idx, inputs: addInputs.value });
        showFlash(okMsg(`${r.lot_no} 에 투입 LOT 를 더했습니다.`, data.warnings));
        addingRun.value = null;
        await refresh();
    } catch (e) {
        errorText.value = failMsg(e);
    } finally {
        busy.value = false;
    }
}
async function cancelInput(x) {
    const ok = await showAlert('연결 해제', `← ${x.lot_no}${x.qty ? ' ' + fmtNum(x.qty) : ''} 연결을 해제합니다.\n기록은 남고 추적에서만 빠집니다.`, 'warning', 'confirm');
    if (!ok.isConfirmed) return;
    busy.value = true; errorText.value = '';
    try {
        await window.axios.post(route('production.opResult.cancelInput'), { run_input_idx: x.run_input_idx });
        showFlash('연결을 해제했습니다.');
        await refresh();
    } catch (e) {
        errorText.value = failMsg(e);
    } finally {
        busy.value = false;
    }
}

// ---- 처리 ----
async function addRun() {
    busy.value = true; errorText.value = '';
    try {
        const { data } = await window.axios.post(route('production.opResult.addRun'), {
            work_order_op_idx: selectedOp.value.work_order_op_idx,
            qty: runForm.qty === '' ? null : runForm.qty,
            equipment_idx: runForm.equipment_idx === '' ? null : Number(runForm.equipment_idx),
            operator_idx: runForm.operator_idx === '' ? null : Number(runForm.operator_idx),
            started_at: runForm.started_at || null,
            worked_at: runForm.worked_at || null,
            remark: runForm.remark || null,
            warehouse_idx: selectedOp.value.receives_on_run && runForm.warehouse_idx !== '' ? Number(runForm.warehouse_idx) : null,
            ...(runLot.value ? { inputs: runForm.inputs } : {}),   // 투입 LOT ( 실적LOT-설계 §5 )
        });
        if (selectedOp.value.receives_on_run && runForm.warehouse_idx !== '') rememberWarehouse(runForm.warehouse_idx);
        showFlash(okMsg(`${selectedOp.value.name} 실적 ${fmtNum(runForm.qty)} 을 넣었습니다.${selectedOp.value.receives_on_run ? ' ( 제품 입고 )' : ''}`, data.warnings));
        // 저장하면 칸을 비워 새 실적을 받는다 ( 작업 일시 = 지금 · 작업자 = 나 — 11-10 ) · 실패하면 그대로( 고쳐서 다시 저장 )
        resetRunForm(selectedOp.value);
        await refresh();
    } catch (e) {
        errorText.value = failMsg(e);
    } finally {
        busy.value = false;
    }
}

async function complete(forceIssue = false) {
    const o = selectedOp.value;
    busy.value = true; errorText.value = '';
    try {
        const { data } = await window.axios.post(route('production.opResult.complete'), {
            work_order_op_idx: o.work_order_op_idx,
            short_reason: doneForm.short_reason || null,
            warehouse_idx: o.needs_warehouse && doneForm.warehouse_idx !== '' ? Number(doneForm.warehouse_idx) : null,
            force_issue: forceIssue,
        });
        if (o.needs_warehouse && doneForm.warehouse_idx !== '') rememberWarehouse(doneForm.warehouse_idx);
        panel.value = '';
        showFlash(okMsg(data.line_status === 'DONE' ? `${line.value.wo_no} 를 완료했습니다.${o.needs_warehouse ? ' ( 남은 수량 제품 입고 )' : ''}` : `${o.name} 을 완료했습니다.`, data.warnings));
        await refresh();
    } catch (e) {
        // 원자재출고 : 덜 나간 자재 → 그대로 완료할지 묻는다
        const short = e.response?.status === 422 ? e.response.data.errors?.issue_short : null;
        if (short && !forceIssue) {
            busy.value = false;
            const r = await showAlert('원자재출고 완료', `${short.join('\n')}\n\n그래도 완료할까요?`, 'warning', 'confirm');
            if (r.isConfirmed) await complete(true);
            return;
        }
        errorText.value = failMsg(e);
    } finally {
        busy.value = false;
    }
}

// 모든 공정 완료 ( 11-12 ) — 남은 공정을 순서대로 한 번에 · 검사 창에서만 끝내는 검사 공정이 있으면 그 앞까지
const allWarehouse = ref('');
async function completeAll() {
    const st   = line.value.complete_all;
    const left = line.value.ops.filter(o => o.status !== 'DONE');
    if (st.needs_warehouse && allWarehouse.value === '') allWarehouse.value = defaultWarehouse();
    if (st.needs_warehouse && allWarehouse.value === '') { errorText.value = '제품을 넣을 창고를 고르세요.'; return; }
    const r = await showAlert('모든 공정 완료',
        `${line.value.wo_no} 의 남은 공정 ${left.length}개를 순서대로 완료합니다.\n${left.map(o => o.name).join(' → ')}`
        + (st.needs_warehouse ? '\n\n남은 수량을 제품 입고하고 줄을 완료합니다.' : ''), 'question', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true; errorText.value = '';
    try {
        const { data } = await window.axios.post(route('production.opResult.completeAll'), {
            work_order_idx: line.value.work_order_idx,
            warehouse_idx: st.needs_warehouse ? Number(allWarehouse.value) : null,
        });
        if (st.needs_warehouse) rememberWarehouse(allWarehouse.value);
        panel.value = '';
        showFlash(okMsg(data.line_status === 'DONE' ? `${line.value.wo_no} 를 완료했습니다. ( 공정 ${data.done.length}개 )` : `공정 ${data.done.length}개를 완료했습니다.`,
            [data.stopped, ...(data.warnings ?? [])].filter(Boolean)));
        await refresh();
    } catch (e) {
        errorText.value = failMsg(e);
    } finally {
        busy.value = false;
    }
}

async function confirmAnd(title, text, fn, done) {
    const r = await showAlert(title, text, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true; errorText.value = '';
    try {
        await fn();
        showFlash(done);
        await refresh();
    } catch (e) {
        errorText.value = failMsg(e);
    } finally {
        busy.value = false;
    }
}

const cancelRun = (r) => confirmAnd('실적 취소', `${r.worked_at} · ${r.operator} · ${fmtNum(r.qty)} 실적을 취소합니다.${r.received ? '\n이 실적으로 입고한 제품도 재고에서 되돌립니다.' : ''}`,
    () => window.axios.post(route('production.opResult.cancelRun'), { run_idx: r.run_idx }), '실적을 취소했습니다.');

const cancelComplete = (o) => confirmAnd('완료 취소', `${o.name} 의 완료를 취소합니다.${o.short_qty ? '\n미달 사유도 지워집니다.' : ''}`,
    () => window.axios.post(route('production.opResult.cancelComplete'), { work_order_op_idx: o.work_order_op_idx }), `${o.name} 완료를 취소했습니다.`);

const cancelLineDone = () => confirmAnd('완료 취소', `${line.value.wo_no} 의 완료를 취소합니다.\n완료할 때 입고한 제품을 재고에서 되돌리고 마지막 공정을 다시 엽니다.\n( 실적마다 입고한 것은 실적 취소로 되돌립니다 · 사용한 자재는 되돌리지 않습니다 )`,
    () => window.axios.post(route('production.opResult.cancelLineDone'), { work_order_idx: line.value.work_order_idx }), `${line.value.wo_no} 완료를 취소했습니다.`);

// 출고 패널 열기 ( 자재 표 아래 ) — 원자재출고 공정의 [출고] 도 여기로
// 공정검사 창 ( 6002 와 같은 창 ) — 판정 · 취소 뒤 이 줄과 목록을 다시 읽는다
const modalStore = useModalStore();
function openInspection(o) {
    modalStore.open(ProcessInspectionForm, {
        opIdx: o.work_order_op_idx,
        onDone: (text, warnings = []) => { showFlash(okMsg(text, warnings)); refresh(); },
        modalTitle: `공정검사 [ ${line.value.wo_no} · ${o.name} ]`,
    }, { size: 'lg', closeOnClickOutside: false });
}

// 자재 출고 · 반납 = 모달 ( 11-12 — 전에는 아래 자재 칸에 펼침 ) · 바뀌면 상세 · 투입 후보를 다시 읽는다
//   공정 줄의 [출고] · [반납] 이 연다 — 창 안에서 탭으로 오갈 수 있고 처음 탭만 다르다 ( 완료된 줄은 반납만 )
const hasIssued = computed(() => (line.value?.materials ?? []).some(m => Number(m.issued) > 0));   // 출고된 자재가 있다 → [반납]
const hasRmOut  = computed(() => (line.value?.ops ?? []).some(o => o.is_rm_out));
function openIssue(first = 'issue') {
    const l = line.value;
    if (!l) return;
    const ret = first === 'return' || l.status === 'DONE';
    modalStore.open(MaterialIssuePanel, {
        modalTitle: `자재 ${ret ? '반납' : '출고'} [ ${l.wo_no} ]`,
        class: 'h-[70vh]',
        initialMode: ret ? 'return' : 'issue',
        woIdx: l.work_order_idx, canWrite: canWrite.value, canCancel: canDelete.value, routes: ISSUE_ROUTES,
        onChanged: () => loadDetail().then(() => runPicker.value?.reload()),
        onFlash: showFlash,
    }, { size: 'xl', closeOnClickOutside: false });
}

// ---- 외주 ( 11-12 — 외주관리 화면으로 가지 않고 그 창을 모달로 ) ----
//   출고 : 외주 출고 대상에서 이 공정 행을 찾아 출고 창 / 입고 : 입고가 남은 출고 건( 하나면 바로 · 여럿이면 고르기 )
const subconCtx = { issue: null, receipt: null };   // 외주 창 선택지 ( 처음 열 때 한 번 )
async function subconFormData(kind) {
    if (!subconCtx[kind]) subconCtx[kind] = (await window.axios.get(route(`production.subcon.${kind}.formData`))).data;
    return subconCtx[kind];
}
const subconDone = (msg) => { showFlash(msg); refresh(); };

async function openSubconIssue(o) {
    busy.value = true;
    try {
        const [ctx, { data }] = await Promise.all([
            subconFormData('issue'),
            window.axios.get(route('production.subcon.issue.candidates'), { params: { keyword: line.value.wo_no, search_field: 'wo_no', _t: Date.now() } }),
        ]);
        const target = (data.rows ?? []).find(r => r.work_order_op_idx === o.work_order_op_idx);
        if (!target || !(target.available_qty > 0)) { showFlash('외주로 내보낼 수량이 없습니다. ( 앞 공정 실적을 먼저 넣으세요 )', true); return; }
        modalStore.open(SubconIssueForm, {
            modalTitle: `외주 출고 [ ${target.wo_no} · ${target.process_name} ]`,
            target, partners: ctx.partners ?? [], now: ctx.now,
            onDone: (msg) => subconDone(msg),
        }, { size: 'lg', closeOnClickOutside: false });
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        busy.value = false;
    }
}

async function openSubconReceipt(o) {
    busy.value = true;
    try {
        const [ctx, { data }] = await Promise.all([
            subconFormData('receipt'),
            window.axios.get(route('production.subcon.receipt.issues'), { params: { open: 1, keyword: line.value.wo_no, search_field: 'wo_no', perPage: 100, _t: Date.now() } }),
        ]);
        const issues = (data.rows ?? []).filter(r => r.work_order_op_idx === o.work_order_op_idx && r.left_qty > 0);
        if (!issues.length) { showFlash('입고할 외주 출고 건이 없습니다. ( [외주 출고]를 먼저 하세요 )', true); return; }
        const openForm = (issue) => modalStore.open(SubconReceiptForm, {
            modalTitle: `외주 입고 [ ${issue.issue_no} ]`,
            issue, warehouses: ctx.warehouses ?? [], inspectionOn: !!ctx.inspection_on, now: ctx.now,
            onDone: (msg) => subconDone(msg),
        }, { size: 'lg', closeOnClickOutside: false });
        if (issues.length === 1) openForm(issues[0]);
        else modalStore.open(SubconIssuePick, { modalTitle: `외주 입고 [ ${line.value.wo_no} · ${o.name} ]`, issues, onPick: openForm }, { size: 'lg' });
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        busy.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.opResult.formData'));
        users.value = data.users ?? []; warehouses.value = data.warehouses ?? []; equipments.value = data.equipments ?? []; reasons.value = data.reasons ?? [];
        me.value = data.me;
        subconUrls.issue = data.subcon_issue_url ?? null; subconUrls.receipt = data.subcon_receipt_url ?? null;
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
    // 첫 조회는 검색바가 search 를 보낸다 — ?wo= 로 들어오면 그 줄을 연다
    const wo = new URLSearchParams(window.location.search).get('wo');
    if (wo) select(Number(wo));
});
</script>

<style scoped>
.lbl { display: block; color: theme('colors.gray.500'); font-weight: 500; font-size: 12px; margin-bottom: 0.25rem; }
</style>
