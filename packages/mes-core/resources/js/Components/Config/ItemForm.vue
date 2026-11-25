<!-- 품목 등록/수정 폼 ( BAS-02 ) — 모달 본체
     - item prop 있으면 수정, 없으면 등록
     - 라우터를 모른다 : 저장 후 onSaved( 저장된 품목 ) 호출 + close ( 닫기·이동은 여는 쪽이 정함 )
     - 탭 : 기본 / 공정( 제품만 ) / 재고·발주 / 단가 / 추가정보 / 첨부( 저장한 뒤 첨부 — AttachFiles )
       공정 : 라우팅 틀( ① )을 고르면 틀의 공정을 불러와 고친다 → 저장하면 이 품목 전용 라우팅( ② ) ( docs/라우팅-3단계-설계.md )
              틀을 고쳐도 이 품목은 그대로 · [틀에서 다시 불러오기] 로 다시 가져온다 · 신규는 기본 틀을 불러온다
       추가정보 : 정책 덮어쓰기( 입고검사 · 출하검사 · 차감 위치 — 대분류별 ) + 리드타임( 기본값 따름 / 품목별 ) + 표준시간( 읽기 전용 )
       커스텀 컬럼은 커스텀 컬럼 기능 개발 시 추가
     - 시리얼 사용 스위치는 기능 스위치 feature.serial 이 켜져 있을 때만 ( 이미 켜진 품목은 끌 수 있게 보인다 )
     - 삭제 없음 : 사용 여부로 내림 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500">{{ errorMsg }}</div>

        <!-- 탭 -->
        <div class="flex gap-1 border-b shrink-0">
            <button
                v-for="t in visibleTabs"
                :key="t.key"
                type="button"
                class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="tab = t.key"
            >
                {{ t.label }}
                <span v-if="t.key === 'files' && fileCount" class="ml-0.5 text-xs text-gray-400">{{ fileCount }}</span>
                <span v-if="t.key === 'partners'" class="text-xs text-gray-400">( {{ itemPartners.length }} )</span>
                <span v-if="tabHasError(t.key)" class="ml-0.5 text-red-500">•</span>
            </button>
        </div>

        <!-- 탭 1 · 기본 -->
        <!-- 4칸 격자 ( 11-25 ) — 창이 넓어( xl ) 2칸이면 입력칸이 600px × 30px 로 길쭉했다 → 짧은 칸 1칸 · 품명 · 규격 · 주 자재는 2칸
               품번( 2 ) | 품명( 2 ) / 대분류 | 소분류 | 규격 | 단위 / 주 자재( 2 ) | 소요 / 조달 구분 | 판매 여부 | 사용 여부 | 시리얼 / 비고( 4 ) -->
        <div v-show="tab === 'basic'" class="grid grid-cols-4 gap-x-4 gap-y-3 pt-4">
            <!-- 새 품목은 비우면 자동 ( 자재 MT · 반제품 SM · 제품 PD + 일련번호 · 11-12 ) -->
            <FormField label="품번" :required="isEdit" :hint="isEdit ? '' : '비우면 자동'" :error="errors.item_code" class="col-span-2">
                <BaseInput v-model="form.item_code" class="w-full" :placeholder="isEdit ? '예: PN-A' : `비우면 자동 ( 예: ${autoCodeHint} )`" :disabled="!canWrite" />
            </FormField>
            <FormField label="품명" required :error="errors.item_name" class="col-span-2">
                <BaseInput v-model="form.item_name" class="w-full" :disabled="!canWrite" />
            </FormField>
            <FormField label="대분류" required :error="errors.item_type">
                <BaseSelect v-model="form.item_type" :options="typeOptions" class="w-full" :disabled="!canWrite" @update:model-value="onTypeChange" />
            </FormField>
            <FormField label="소분류" required :error="errors.item_kind">
                <BaseSelect v-model="form.item_kind" :options="kindOptions" class="w-full" :disabled="!canWrite" />
            </FormField>
            <FormField label="규격" :error="errors.spec">
                <!-- 코드관리 규격에서 찾기 · 새 규격도 그대로 ( 저장하면 규격 코드에 추가 ) -->
                <SpecPicker v-model="form.spec" :can-create="canWrite" :disabled="!canWrite" />
            </FormField>
            <FormField label="단위" required :error="errors.spec_unit">
                <UnitSelect v-model="form.spec_unit" :disabled="!canWrite" empty-hint />
            </FormField>

            <!-- 재질 일단 보류 -->
            <!-- <div>
                <label class="block text-gray-500 font-medium mb-1">재질</label>
                <BaseInput v-model="form.material" class="w-full" maxlength="100" :disabled="!canWrite" />
                <p v-if="errors.material" class="text-xs text-red-500 mt-0.5">{{ errors.material[0] }}</p>
            </div> -->

            <!-- 주 자재( BOM ) 는 만드는 품목( 제품 )만 · 공정은 공정 탭 -->
            <!-- 주 자재 : 가공품은 제품 1 : 원자재 1 이 대부분 → 저장하면 BOM V1 이 만들어진다 -->
            <div v-if="isProduct" class="col-span-4 border-t pt-3">
                <div class="flex items-baseline gap-2 mb-1">
                    <span class="text-gray-500 font-medium">주 자재</span>
                    <span v-if="bomLocked" class="text-xs text-amber-600">BOM 자재가 {{ bomLineCount }}건이라 여기서는 수정할 수 없습니다. BOM관리에서 다루세요.</span>
                    <span v-else class="text-xs text-gray-400">저장하면 BOM {{ mainMaterial.bom_line_idx ? '자재 줄이 수정' : 'V1 이 자동 생성' }}됩니다. 비우면 줄이 삭제됩니다.</span>
                </div>
                <div class="grid grid-cols-4 gap-x-4 gap-y-2">
                    <div class="col-span-2">
                        <!-- 품번 · 품명 · 규격으로 찾기 · 없으면 새 자재 등록 ( 품번이 같아도 규격이 다르면 다른 자재 ) -->
                        <ItemPicker v-model="mainMaterial.child_item_idx" :remote="{ types: ['MATERIAL'] }" :exclude="[form.item_idx]" placeholder="( 없음 )" allow-empty size="md"
                            :can-create="canWrite" :disabled="!canWrite || bomLocked"
                            :current="mainMaterial.child_item_name ? { item_code: mainMaterial.child_item_code, item_name: mainMaterial.child_item_name, spec: mainMaterial.child_item_spec } : null" />
                        <p v-if="errors['main_material.child_item_idx']" class="text-xs text-red-500 mt-0.5">{{ errors['main_material.child_item_idx'][0] }}</p>
                        <!-- 이미 주 자재가 있는데 다른 자재를 고르면 : 교체 / 대체 자재로 추가 ( docs/대체자재-수주자재-설계.md ) -->
                        <div v-if="mainChanged" class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-0.5 text-xs text-gray-600">
                            <label class="inline-flex items-center gap-1 cursor-pointer">
                                <input type="radio" class="accent-blue-600" value="REPLACE" v-model="mainMaterial.mode" :disabled="!canWrite" /> 주 자재 교체
                            </label>
                            <label class="inline-flex items-center gap-1 cursor-pointer">
                                <input type="radio" class="accent-blue-600" value="SUBSTITUTE" v-model="mainMaterial.mode" :disabled="!canWrite" /> 대체 자재로 추가
                                <span class="text-gray-400">( 주 자재 {{ originalMainName }} 는 그대로 · 소요는 이 자재 기준 )</span>
                            </label>
                        </div>
                        <p v-else-if="mainMaterial.substitute_count" class="mt-0.5 text-xs text-gray-400">대체 자재 {{ mainMaterial.substitute_count }}개 ( BOM관리에서 관리 )</p>
                    </div>
                    <div>
                        <BaseInput v-model="mainMaterial.qty" type="number" min="0" class="w-full text-right" placeholder="소요" :disabled="!canWrite || bomLocked || !mainMaterial.child_item_idx" />
                        <p v-if="errors['main_material.qty']" class="text-xs text-red-500 mt-0.5">{{ errors['main_material.qty'][0] }}</p>
                    </div>
                </div>
            </div>

            <!-- 조달 구분 · 판매 여부 ( 11-13 ) — 발주서에는 구매만 · 수주 / 출하에는 판매만 나온다 -->
            <div>
                <label class="block text-gray-500 font-medium mb-1">조달 구분</label>
                <BaseSelect v-model="form.procure_type" :options="procureOptions" class="w-full" :disabled="!canWrite" />
                <p class="text-xs text-gray-400 mt-0.5">{{ procureHint }}</p>
                <p v-if="errors.procure_type" class="text-xs text-red-500 mt-0.5">{{ errors.procure_type[0] }}</p>
            </div>
            <FormField label="판매 여부" :error="errors.is_sale">
                <div class="h-8 flex items-center">
                    <BaseCheckbox variant="switch" v-model="form.is_sale" true-value="Y" false-value="N" :disabled="!canWrite">
                        {{ form.is_sale === 'Y' ? '판매함' : '판매 안 함' }}
                    </BaseCheckbox>
                </div>
            </FormField>

            <FormField label="사용 여부">
                <div class="h-8 flex items-center">
                    <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite">
                        {{ form.is_use === 'Y' ? '사용' : '미사용' }}
                    </BaseCheckbox>
                </div>
            </FormField>
            <FormField v-if="serialEnabled || form.serial_use === 'Y'" label="시리얼" hint="낱개마다 번호 — 중요 제품만" :error="errors.serial_use">
                <div class="h-8 flex items-center">
                    <BaseCheckbox variant="switch" v-model="form.serial_use" true-value="Y" false-value="N" :disabled="!canWrite">
                        {{ form.serial_use === 'Y' ? '사용' : '사용 안 함' }}
                    </BaseCheckbox>
                </div>
            </FormField>
            <!-- 비고 : 처음엔 한 줄( 다른 칸과 같은 높이 ) · 길면 모서리를 끌어 늘린다 / ! : 공통 textarea.base-input 이 최소 2줄 높이를 정해 두어서 -->
            <FormField label="비고" class="col-span-4">
                <textarea v-model="form.remark" rows="1" class="base-input w-full !min-h-8 !py-[5px] !leading-5" style="height: 32px" :class="!canWrite ? 'resize-none': 'resize-y'" :disabled="!canWrite"></textarea>
            </FormField>
        </div>

        <!-- 탭 · 공정 ( 제품만 ) -->
        <div v-if="isProduct" v-show="tab === 'routing'" class="pt-4">
            <div class="flex items-end gap-4 mb-3">
                <FormField label="라우팅 틀" :error="errors.routing_idx" class="w-80 shrink-0">
                    <BaseSelect :model-value="form.routing_idx" :options="routingOptions" class="w-full" :disabled="!canWrite" @update:model-value="onTemplateChange" />
                </FormField>
                <p class="pb-1.5 text-xs text-gray-400 leading-relaxed">
                    틀의 공정을 불러와 이 품목에 맞게 고칩니다. 저장하면 <b class="font-semibold text-gray-500">이 품목 전용 공정</b>으로 저장되고,<br />
                    공정 라우팅 관리에서 틀을 고쳐도 이 품목은 바뀌지 않습니다.
                </p>
            </div>
            <RoutingLinesEditor
                :lines="routingLines" :processes="processes" :readonly="!canWrite" :errors="errors"
                error-prefix="routing_lines" empty-text="공정이 없습니다. 틀을 고르거나 공정을 추가하세요."
            >
                <template #actions>
                    <BaseButton v-if="canWrite && form.routing_idx" size="sm" variant="secondary" :disabled="templateLoading" @click="reloadTemplate">틀에서 다시 불러오기</BaseButton>
                </template>
            </RoutingLinesEditor>
            <p class="mt-2 text-xs text-gray-400">1개 생산 표준시간 = 개당 시간 합계 <b class="text-gray-600">{{ fmtMin(cycleTime) }}</b></p>
        </div>

        <!-- 탭 2 · 재고 · 발주 -->
        <div v-show="tab === 'stock'" class="pt-4">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                <div v-for="f in STOCK_FIELDS" :key="f.key">
                    <label class="block text-gray-500 font-medium mb-1">
                        {{ f.label }} <span v-if="f.suffix" class="text-xs text-gray-400">( {{ f.suffix }} )</span>
                    </label>
                    <BaseInput
                        v-model="form[f.key]" type="number" min="0" class="w-full text-right"
                        :placeholder="f.nullable ? '미입력' : ''"
                        :empty-value="f.nullable ? null : 0"
                        :disabled="!canWrite"
                    />
                    <p v-if="errors[f.key]" class="text-xs text-red-500 mt-0.5">{{ errors[f.key][0] }}</p>
                </div>
            </div>

            <!-- 배수 · 최소 수량 설명 + 현재 입력값으로 계산한 지시 수량 -->
            <div class="mt-3 px-3 py-2.5 rounded bg-gray-50 text-xs text-gray-600 leading-relaxed">
                <p class="font-semibold text-gray-700 mb-1">배수란?</p>
                <p>
                    발주·작업지시 수량을 <b>몇 개 단위로 맞출지</b> 정하는 값입니다.
                    필요한 수량을 이 값의 배수로 <b>올림</b>합니다. <b>0이면 올림하지 않습니다.</b>
                </p>
                <ul class="mt-1.5 space-y-0.5 list-disc pl-4">
                    <li><b>최소 수량</b> — 한 번에 발주·지시할 수 있는 하한. 필요량이 이보다 적으면 최소 수량까지 올립니다.</li>
                    <li><b>배수</b> — 최소 수량을 적용한 뒤, 이 단위로 올립니다. <span class="text-gray-400">예: 500개 봉지로만 구매 → 500</span></li>
                    <li><b>포장 수량</b> — 한 박스(봉지)에 실제로 담기는 개수. 입고·출하 라벨과 박스 수 계산용이며 발주 수량에는 쓰이지 않습니다.</li>
                </ul>
                <p class="mt-1.5 text-gray-400">
                    포장 단위로만 사고파는 품목은 포장 수량과 배수를 같게 넣습니다.
                    낱개로도 살 수 있으면 배수는 0, 박스(봉지 여러 개) 단위로만 살 수 있으면 박스 수량을 넣습니다.
                </p>
                <p class="mt-2 pt-2 border-t border-gray-200">
                    <span class="text-gray-400 mr-1">현재 입력값으로 계산</span>
                    필요 <b>{{ fmtNum(PREVIEW_NEED) }}</b>
                    → 최소 {{ fmtNum(num(form.moq)) }} 적용 <b>{{ fmtNum(previewMoq) }}</b>
                    → 배수 {{ num(form.unit_round) > 0 ? fmtNum(num(form.unit_round)) + ' 단위 올림' : '없음' }}
                    → 지시 <b class="text-blue-700">{{ fmtNum(previewOrder) }}</b>
                </p>
            </div>

        </div>

        <!-- 탭 3 · 단가 -->
        <div v-show="tab === 'price'" class="pt-4">
            <div class="grid grid-cols-3 gap-x-4 gap-y-3">
                <FormField v-for="f in PRICE_FIELDS" :key="f.key" :label="f.label" :error="errors[f.key]">
                    <BaseInput
                        v-model="form[f.key]" type="number" min="0" class="w-full text-right"
                        :empty-value="0"
                        :disabled="!canWrite"
                    />
                </FormField>
            </div>
            <p class="mt-3 text-xs text-gray-400">전표 라인의 기본값입니다. 전표에서 고친 값은 라인에 저장되며, 여기를 바꿔도 기존 전표는 그대로입니다.</p>
        </div>

        <!-- 탭 · 거래처 ( 11-25 ) — 거래처 등록의 담당자 탭처럼 줄로 · 구매처만 · 주 거래처 하나( 첫 줄이 기본 ) -->
        <div v-show="tab === 'partners'" class="pt-4">
            <div class="flex items-center justify-between mb-2">
                <p class="text-xs text-gray-400">이 품목을 사는 구매처입니다. <b class="font-semibold text-gray-500">주</b>에 표시한 거래처가 주 거래처입니다. 단가는 그 거래처와의 단가입니다.</p>
                <BaseButton v-if="canWrite" size="sm" variant="secondary" @click="addPartner">+ 거래처</BaseButton>
            </div>
            <p v-if="errors.partners" class="mb-2 text-xs text-red-500">{{ errors.partners[0] }}</p>

            <div class="overflow-x-auto border rounded">
                <table class="w-full min-w-[860px] table-fixed text-sm">
                    <colgroup>
                        <col class="w-12">
                        <col class="w-72">
                        <col class="w-36">
                        <col class="w-40">
                        <col class="w-36">
                        <col>
                        <col v-if="canWrite" class="w-10">
                    </colgroup>
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-2 py-1.5 font-medium">주</th>
                            <th class="px-2 py-1.5 font-medium">거래처 <span class="text-red-400">*</span></th>
                            <th class="px-2 py-1.5 font-medium">담당자</th>
                            <th class="px-2 py-1.5 font-medium">번호</th>
                            <th class="px-2 py-1.5 font-medium">단가</th>
                            <th class="px-2 py-1.5 font-medium">비고</th>
                            <th v-if="canWrite" class="px-2 py-1.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!itemPartners.length">
                            <td :colspan="canWrite ? 7 : 6" class="px-2 py-4 text-center text-gray-400">거래처가 없습니다.</td>
                        </tr>
                        <tr v-for="(p, i) in itemPartners" :key="p._rowKey" class="border-t align-top" :class="p.is_main === 'Y' ? 'bg-blue-50/40' : ''">
                            <td class="px-1.5 py-1 text-center">
                                <label class="inline-flex items-center justify-center h-8 w-full" :class="canWrite ? 'cursor-pointer' : 'cursor-not-allowed'" title="주 거래처">
                                    <input type="radio" name="item-main-partner" class="accent-blue-600" :checked="p.is_main === 'Y'" :disabled="!canWrite" @change="setMainPartner(i)" />
                                </label>
                            </td>
                            <td class="px-1.5 py-1">
                                <PartnerSelect v-model="p.partner_idx" :options="supplierPartners" :current="p.partner_name ? { partner_name: p.partner_name } : null" current-note="구매처 아님 · 미사용" placeholder="구매처 선택" class="w-full" :disabled="!canWrite" />
                                <p v-if="rowError(i, 'partner_idx')" class="text-[11px] text-red-500">{{ rowError(i, 'partner_idx') }}</p>
                            </td>
                            <td class="px-1.5 py-1">
                                <BaseInput v-model="p.contact_name" maxlength="50" :disabled="!canWrite" />
                                <p v-if="rowError(i, 'contact_name')" class="text-[11px] text-red-500">{{ rowError(i, 'contact_name') }}</p>
                            </td>
                            <td class="px-1.5 py-1">
                                <BaseInput v-model="p.contact_phone" maxlength="30" placeholder="010-0000-0000" :disabled="!canWrite" />
                                <p v-if="rowError(i, 'contact_phone')" class="text-[11px] text-red-500">{{ rowError(i, 'contact_phone') }}</p>
                            </td>
                            <td class="px-1.5 py-1">
                                <BaseInput v-model="p.unit_price" type="number" min="0" class="w-full text-right" :disabled="!canWrite" />
                                <p v-if="rowError(i, 'unit_price')" class="text-[11px] text-red-500">{{ rowError(i, 'unit_price') }}</p>
                            </td>
                            <td class="px-1.5 py-1">
                                <BaseInput v-model="p.remark" maxlength="500" :disabled="!canWrite" />
                                <p v-if="rowError(i, 'remark')" class="text-[11px] text-red-500">{{ rowError(i, 'remark') }}</p>
                            </td>
                            <td v-if="canWrite" class="px-1.5 text-center py-1 leading-8">
                                <button type="button" class="inline-flex items-center h-8 align-top px-1.5 text-gray-400 hover:text-red-500" title="줄 삭제" @click="removePartner(i)">✕</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 탭 4 · 추가정보 -->
        <div v-show="tab === 'extra'" class="pt-4 space-y-4">
            <!-- 품질 -->
            <section>
                <div class="mb-2 text-xs font-semibold text-gray-500 border-b pb-1">품질</div>
                <div class="space-y-3">
                    <PolicyOverrideField
                        v-if="fieldOf('quality.incoming_inspection')"
                        v-model="policies['quality.incoming_inspection']"
                        :field="fieldOf('quality.incoming_inspection')"
                        label="입고검사"
                        :disabled="!canWrite"
                        :error="policyError('quality.incoming_inspection')"
                    >
                        <p class="text-xs text-gray-400 mt-1">
                            거래처에 입고검사 설정이 있으면 거래처를 먼저 따릅니다. 거래처에 없을 때 이 값을, 이 값도 없으면 시스템 설정( 입고검사 사용 )을 따릅니다.
                        </p>
                    </PolicyOverrideField>
                    <!-- 입고검사 사용 = N 이면 칸이 없다 ( depends_on ) → 빈 자리 대신 이유 안내 ( 11-23 ) -->
                    <div v-else-if="(policyKeysByType[form.item_type] ?? []).includes('quality.incoming_inspection')">
                        <div class="mb-1 text-gray-500 font-medium">입고검사</div>
                        <p class="text-xs text-gray-500 leading-5">현재는 해당 설정이 꺼져 있어 모든 입고가 검사 없이 재고에 잡힙니다.</p>
                    </div>
                    <PolicyOverrideField
                        v-if="showPolicy('quality.shipping_inspection')"
                        v-model="policies['quality.shipping_inspection']"
                        :field="fieldOf('quality.shipping_inspection')"
                        label="출하검사"
                        :disabled="!canWrite"
                        :error="policyError('quality.shipping_inspection')"
                    />
                </div>
            </section>

            <!-- 차감 위치 ( 자재는 자재 차감, 제품은 제품 차감 ) -->
            <section v-if="consumeKeys.length">
                <div class="mb-2 text-xs font-semibold text-gray-500 border-b pb-1">차감 위치</div>
                <div class="space-y-3">
                    <PolicyOverrideField
                        v-for="k in consumeKeys"
                        :key="k"
                        v-model="policies[k]"
                        :field="fieldOf(k)"
                        :disabled="!canWrite"
                        :error="policyError(k)"
                    />
                </div>
            </section>

            <!-- 리드타임 ( BAS-09 의 품목 행 ) -->
            <section>
                <div class="mb-2 text-xs font-semibold text-gray-500 border-b pb-1">
                    리드타임 <span class="font-normal text-gray-400">( 일 · 수량과 무관 )</span>
                </div>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1 min-h-8">
                    <label class="inline-flex items-center gap-1.5" :class="!canWrite ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'">
                        <input type="radio" class="accent-blue-600" value="DEFAULT" v-model="leadTime.mode" :disabled="!canWrite" />
                        <span>기본값</span>
                        <span class="text-xs text-gray-400">( {{ defaultLeadText }} )</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5" :class="!canWrite ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'">
                        <input type="radio" class="accent-blue-600" value="ITEM" v-model="leadTime.mode" :disabled="!canWrite" />
                        <span>품목별 입력</span>
                    </label>
                </div>
                <div v-if="leadTime.mode === 'ITEM'" class="mt-2 grid grid-cols-6 gap-2 items-end">
                    <div v-for="st in LEAD_STAGES" :key="st.key">
                        <span class="block text-xs text-gray-500 mb-0.5">{{ st.label }}</span>
                        <BaseInput v-model="leadTime[st.key]" type="number" min="0" class="w-full text-right" :disabled="!canWrite" />
                    </div>
                    <div>
                        <span class="block text-xs text-gray-500 mb-0.5">계</span>
                        <p class="h-8 flex items-center justify-end pr-1 font-semibold text-gray-700">{{ leadTotal }} 일</p>
                    </div>
                </div>
                <p v-if="leadTime.mode === 'ITEM'" class="text-xs text-gray-400 mt-1">비운 칸은 해당 없음( 0일 )입니다. 칸별로 기본값을 따르지 않습니다.</p>
                <p v-if="errors.lead_time" class="text-xs text-red-500 mt-0.5">{{ errors.lead_time[0] }}</p>
                <p v-for="st in LEAD_STAGES" v-show="errors['lead_time.' + st.key]" :key="'e' + st.key" class="text-xs text-red-500 mt-0.5">
                    {{ st.label }} : {{ errors['lead_time.' + st.key]?.[0] }}
                </p>
            </section>

            <!-- 표준시간 ( 읽기 전용 ) -->
            <section>
                <div class="mb-2 text-xs font-semibold text-gray-500 border-b pb-1">1개 생산 표준시간</div>
                <p class="text-gray-700">
                    <b>{{ fmtMin(cycleTime) }}</b>
                    <span class="ml-1 text-xs text-gray-400">
                        {{ routingLines.length ? '공정 탭의 공정 개당 시간 합계' : '공정이 없습니다' }} · 읽기 전용
                    </span>
                </p>
            </section>
        </div>

        <!-- 탭 5 · 첨부 ( 도면 등 — 수정 : 올리기 · 삭제는 바로 저장 / 신규 : 골라 두면 저장할 때 올린다 ) -->
        <div v-show="tab === 'files'" class="pt-4">
            <AttachFiles ref="attachRef" deferred ref-type="item" :ref-id="form.item_idx" category="DRAWING" :readonly="!canWrite" @change="n => fileCount = n" />
            <p v-if="isEdit && canWrite" class="mt-2 text-xs text-gray-400">첨부는 올리거나 지우면 바로 반영됩니다. ( [수정]을 누르지 않아도 됩니다 )</p>
        </div>

        <!-- 액션 -->
        <ModalFooter>
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ canWrite ? '취소' : '닫기' }}</BaseButton>
            <BaseButton v-if="canWrite" variant="primary" size="sm" :disabled="saving" @click="save">
                {{ isEdit ? '저장' : '등록' }}
            </BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import FormField from '@core/Components/Common/FormField.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { ITEM_TYPES, ITEM_KINDS } from '@core/Components/Config/itemCodes';
import PolicyOverrideField from '@core/Components/Config/PolicyOverrideField.vue';
import AttachFiles from '@core/Components/Common/AttachFiles.vue';
import RoutingLinesEditor from '@core/Components/Config/RoutingLinesEditor.vue';
import ItemPicker from '@core/Components/Common/ItemPicker.vue';
import SpecPicker from '@core/Components/Config/SpecPicker.vue';
import { toLinePayload, totalCycleOf, fmtMin } from '@core/Components/Config/routingLines';
import { showAlert } from '@core/Utils/message';
import UnitSelect from '@core/Components/Common/UnitSelect.vue';
import PartnerSelect from '@core/Components/Common/PartnerSelect.vue';
import { fmtNum } from '@core/Utils/format';

// 신규 등록 때 골라 둔 첨부를 저장 뒤 올린다 ( AttachFiles deferred · 11-12 ) — 실패한 파일만 알린다
const attachRef = ref(null);
async function flushAttach(refId) {
    const failed = (await attachRef.value?.flush(refId)) ?? [];
    if (failed.length) await showAlert('첨부 일부 실패', `저장은 되었고, 아래 파일은 올리지 못했습니다. 수정 화면에서 다시 올리세요.\n${failed.join('\n')}`, 'warning');
}

const props = defineProps({
    item:     { type: Object,   default: null },   // 있으면 수정, 없으면 등록
    canWrite: { type: Boolean,  default: false },
    onSaved:  { type: Function, default: null },   // 저장 후 호출 ( 저장된 품목 전달 )
    onlyTypes: { type: Array,   default: null },   // 고를 수 있는 대분류만 ( 수주의 [+ 새 품목 등록] = ['PRODUCT'] ) · 없으면 전부
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.item?.item_idx);
// 품번 자동 생성 예시 ( 서버 CodeGenerator 와 같은 머리글자 )
const autoCodeHint = computed(() => `${form.item_type === 'MATERIAL' ? 'MT' : form.item_kind === 'SEMI' ? 'SM' : 'PD'}-0001`);

const TABS = [
    { key: 'basic', label: '기본' },
    { key: 'routing', label: '공정' },
    // { key: 'stock', label: '재고·발주' }, // 발주 주기, 포장 관련 상세 내용이 포함되어있으나, 현재는 기능이 없으므로 숨김 처리
    { key: 'price', label: '단가' },
    { key: 'partners', label: '거래처' },   // 11-25 : 구매처 N · 주 거래처 하나
    { key: 'extra', label: '추가정보' },
    { key: 'files', label: '첨부' },
];

const fileCount = ref(0);   // 첨부 탭 머리의 개수

const LEAD_STAGES = [
    { key: 'purchase_days', label: '구매' },
    { key: 'receive_days',  label: '입고' },
    { key: 'produce_days',  label: '생산' },
    { key: 'inspect_days',  label: '검사' },
    { key: 'pack_days',     label: '포장' },
];

const STOCK_FIELDS = [
    // nullable : 빈값이 "미입력" 의미 ( DB NULL 허용 ) → 비워도 0 으로 채우지 않음 ( BaseInput empty-value 미지정 )
    { key: 'pack_qty',         label: '포장 수량',  suffix: '박스당', nullable: true },
    { key: 'safety_stock',     label: '안전재고' },
    { key: 'order_cycle_days', label: '발주 주기',  suffix: '일' },
    { key: 'moq',              label: '최소 수량' },
    { key: 'unit_round',       label: '배수',       suffix: '이 배수로 올림' },
];

const PRICE_FIELDS = [
    { key: 'std_sale_price',   label: '판매단가' },
    { key: 'std_buy_price',    label: '구매단가' },
    { key: 'std_subcon_price', label: '외주단가' },
];

// 오류 필드 → 탭 ( 저장 실패 시 해당 탭으로 이동 )
const FIELD_TAB = {
    ...Object.fromEntries(STOCK_FIELDS.map(f => [f.key, 'stock'])),
    min_usable_spec: 'stock', calc_code: 'stock', spec_dim: 'stock',
    ...Object.fromEntries(PRICE_FIELDS.map(f => [f.key, 'price'])),
};
// main_material.* 는 기본 탭, 라우팅은 공정 탭, 정책 · 리드타임은 추가정보 탭
const tabOf = (field) => field.startsWith('routing_') ? 'routing'
    : field.startsWith('partners') ? 'partners'
    : (field.startsWith('policies.') || field.startsWith('lead_time')) ? 'extra' : (FIELD_TAB[field] ?? 'basic');

const tab = ref('basic');

// ---- 폼 ----
const src = props.item ?? {};
const form = reactive({
    item_idx:         src.item_idx ?? null,
    item_code:        src.item_code ?? '',
    item_name:        src.item_name ?? '',
    item_type:        src.item_type ?? props.onlyTypes?.[0] ?? 'PRODUCT',
    item_kind:        src.item_kind ?? 'FINISHED',
    spec_unit:        src.spec_unit ?? 'EA',
    spec:             src.spec ?? '',
    material:         src.material ?? '',
    serial_use:       src.serial_use ?? 'N',
    // 조달 구분 · 판매 여부 — 새 품목은 대분류 기본값 ( 자재 = 구매 · 판매 안 함 / 제품 = 해당 없음 · 판매 )
    procure_type:     src.procure_type ?? ((src.item_type ?? props.onlyTypes?.[0] ?? 'PRODUCT') === 'MATERIAL' ? 'BUY' : 'NONE'),
    is_sale:          src.is_sale ?? ((src.item_type ?? props.onlyTypes?.[0] ?? 'PRODUCT') === 'MATERIAL' ? 'N' : 'Y'),
    pack_qty:         src.pack_qty ?? '',
    safety_stock:     src.safety_stock ?? 0,
    order_cycle_days: src.order_cycle_days ?? 0,
    moq:              src.moq ?? 0,
    unit_round:       src.unit_round ?? 0,
    min_usable_spec:  src.min_usable_spec ?? '',
    calc_code:        src.calc_code ?? '',
    std_sale_price:   src.std_sale_price ?? 0,
    std_buy_price:    src.std_buy_price ?? 0,
    std_subcon_price: src.std_subcon_price ?? 0,
    is_use:           src.is_use ?? 'Y',
    remark:           src.remark ?? '',
    routing_idx:      '',   // 라우팅 틀( ① ) — 품목 라우팅( ② )의 틀은 formData 에서
});

// ---- 거래처 탭 ( 11-25 ) ----
const itemPartners     = reactive([]);   // [{ item_partner_idx, _rowKey, partner_idx, partner_name, is_main, contact_name, contact_phone, unit_price, remark }]
const supplierPartners = ref([]);        // 고를 수 있는 구매처 ( 사용 중 )
let partnerSeq = 0;
const newPartnerKey = () => `n${++partnerSeq}`;
function addPartner() {
    itemPartners.push({
        item_partner_idx: null, _rowKey: newPartnerKey(), partner_idx: '', partner_name: '',
        is_main: itemPartners.length ? 'N' : 'Y',   // 첫 줄 = 주 거래처
        contact_name: '', contact_phone: '', unit_price: '', remark: '',
    });
}
function setMainPartner(i) {
    itemPartners.forEach((p, k) => { p.is_main = k === i ? 'Y' : 'N'; });
}
function removePartner(i) {
    const wasMain = itemPartners[i].is_main === 'Y';
    itemPartners.splice(i, 1);
    if (wasMain && itemPartners.length) itemPartners[0].is_main = 'Y';   // 주 거래처를 지우면 첫 줄이 주
}
const rowError = (i, field) => errors.value[`partners.${i}.${field}`]?.[0] ?? '';

// ---- 라우팅 틀 · 공정 · 주 자재 ( BOM ) ----
const routings     = ref([]);
const defaultRoutingIdx = ref(null);   // 신규 등록 기본 틀 ( 대분류를 제품으로 되돌릴 때 다시 불러옴 )
const processes    = ref([]);
const routingLines = reactive([]);      // 품목 라우팅( ② ) 공정 줄 — 편집기가 그 자리에서 고친다
const baseInfo     = ref(null);         // 지금 틀 { name, is_use } ( 미사용으로 내려간 틀 표시용 )
const templateLoading = ref(false);
const bomLineCount = ref(0);
const mainMaterial = reactive({ bom_line_idx: null, child_item_idx: '', qty: '', calc_code: null, ext_n01: null, substitute_count: 0, mode: 'REPLACE' });
const originalMain = ref(null);   // 저장된 주 자재 { idx, name } — 다른 자재를 고르면 교체 / 대체를 묻는다
const mainChanged  = computed(() => !!originalMain.value && mainMaterial.child_item_idx !== '' && Number(mainMaterial.child_item_idx) !== Number(originalMain.value.idx));
const originalMainName = computed(() => originalMain.value?.name ?? '');

// BOM 자재가 2건 이상이면 주 자재 칸은 읽기 전용 ( BOM관리에서 다룬다 )
const bomLocked = computed(() => bomLineCount.value >= 2);

const routingOptions = computed(() => {
    const opts = [
        { value: '', label: '( 없음 )' },
        ...routings.value.map(r => ({ value: r.routing_idx, label: `${r.routing_name} [${r.routing_code}]${r.is_default === 'Y' ? ' · 기본' : ''}` })),
    ];
    // 지금 틀이 미사용으로 내려가 목록에 없으면 표시용으로 추가
    if (form.routing_idx && !opts.some(o => Number(o.value) === Number(form.routing_idx))) {
        opts.push({ value: form.routing_idx, label: `${baseInfo.value?.name ?? form.routing_idx} ( 미사용 )` });
    }
    return opts;
});

// 틀의 공정을 불러와 편집기에 갈아 끼운다
async function loadTemplate(idx) {
    templateLoading.value = true;
    try {
        const { data } = await window.axios.get(route('config.item.routingTemplate', idx));
        routingLines.splice(0, routingLines.length, ...(data.lines ?? []));
    } catch (e) {
        errorMsg.value = '라우팅 틀의 공정을 불러오지 못했습니다.';
    } finally {
        templateLoading.value = false;
    }
}

// 틀 바꾸기 : 공정을 새 틀로 갈아 끼운다 ( 있던 공정이 있으면 묻는다 ) · ( 없음 ) 은 공정을 그대로 두고 틀만 뺀다
async function onTemplateChange(v) {
    if (v !== '' && routingLines.length) {
        const r = await showAlert('라우팅 틀 변경', '지금 공정을 지우고 고른 틀의 공정으로 바꿉니다. 계속할까요?', 'warning', 'confirm');
        if (!r.isConfirmed) return;
    }
    form.routing_idx = v;
    if (v !== '') await loadTemplate(v);
}

async function reloadTemplate() {
    const r = await showAlert('틀에서 다시 불러오기', '지금 공정을 지우고 틀의 공정을 다시 불러옵니다. 계속할까요?', 'warning', 'confirm');
    if (r.isConfirmed) await loadTemplate(form.routing_idx);
}

async function loadFormData() {
    try {
        const { data } = await window.axios.get(route('config.item.formData'), {
            params: form.item_idx ? { item_idx: form.item_idx } : {},
        });
        routings.value     = data.routings ?? [];
        supplierPartners.value = data.supplier_partners ?? [];
        itemPartners.splice(0, itemPartners.length, ...(data.item_partners ?? []).map(p => ({
            ...p, _rowKey: `p${p.item_partner_idx}`,
            contact_name: p.contact_name ?? '', contact_phone: p.contact_phone ?? '',
            unit_price: p.unit_price ?? '', remark: p.remark ?? '',
        })));
        processes.value    = data.processes ?? [];
        bomLineCount.value = data.bom_line_count ?? 0;

        if (data.main_material) {
            Object.assign(mainMaterial, data.main_material);
            originalMain.value = { idx: data.main_material.child_item_idx, name: data.main_material.child_item_name };
        }

        // 탭 4 : 정책 덮어쓰기 · 리드타임 · 시리얼 기능
        policyFields.value   = data.policies ?? [];
        policyKeysByType.value = data.policy_keys_by_type ?? {};
        policyFields.value.forEach(f => { policies[f.key] = f.override ?? ''; });
        serialEnabled.value  = data.serial_enabled === true;
        defaultLead.value    = data.lead_time?.default ?? null;
        const own = data.lead_time?.item;
        leadTime.mode = own && own.is_use === 'Y' ? 'ITEM' : 'DEFAULT';
        LEAD_STAGES.forEach(st => { leadTime[st.key] = own?.[st.key] ?? ''; });
        // 품목 라우팅( ② ) : 틀 · 공정 / 신규 등록( 제품 )은 기본 틀의 공정을 불러온다
        defaultRoutingIdx.value = data.default_routing_idx ?? null;
        const ir = data.item_routing;
        if (ir) {
            form.routing_idx = ir.base_routing_idx ?? '';
            baseInfo.value   = { name: ir.base_name, is_use: ir.base_is_use };
            routingLines.splice(0, routingLines.length, ...(ir.lines ?? []));
        } else if (!isEdit.value && isProduct.value && data.default_routing_idx) {
            form.routing_idx = data.default_routing_idx;
            await loadTemplate(data.default_routing_idx);
        }
    } catch (e) {
        errorMsg.value = '라우팅 · 자재 목록을 불러오지 못했습니다.';
    }
}

onMounted(loadFormData);

const kindOptions = computed(() => ITEM_KINDS[form.item_type] ?? []);
// 대분류 선택지 ( onlyTypes 를 주면 그것만 — 수주에서 등록하는 품목은 제품만 )
const typeOptions = computed(() => props.onlyTypes ? ITEM_TYPES.filter(t => props.onlyTypes.includes(t.value)) : ITEM_TYPES);

// ---- 탭 4 · 추가정보 ----
const policyFields     = ref([]);        // PolicyService::entityFields
const policyKeysByType = ref({});        // 대분류 → 덮어쓸 수 있는 정책 키
const policies         = reactive({});   // key → 덮어쓴 값 ( '' = 설정에 맞춤 )
const serialEnabled    = ref(false);
const defaultLead      = ref(null);      // 리드타임 기본값 행 ( 없으면 null )
const leadTime         = reactive({ mode: 'DEFAULT', purchase_days: '', receive_days: '', produce_days: '', inspect_days: '', pack_days: '' });

const fieldOf     = (key) => policyFields.value.find(f => f.key === key) ?? null;
const showPolicy  = (key) => !!fieldOf(key) && (policyKeysByType.value[form.item_type] ?? []).includes(key);
const policyError = (key) => errors.value[`policies.${key}`]?.[0] ?? '';
// 차감 위치 : 대분류의 키 중 consume_* ( 자재 → 자재 차감 3개 / 제품 → 제품 차감 1개 )
const consumeKeys = computed(() => (policyKeysByType.value[form.item_type] ?? []).filter(k => k.includes('.consume_') && fieldOf(k)));

// 1개 생산 표준시간 : 공정 탭 공정의 개당 시간 합계 ( 저장하면 품목에도 같은 값이 들어간다 )
const cycleTime = computed(() => totalCycleOf(routingLines));

const leadTotal = computed(() => LEAD_STAGES.reduce((s, st) => s + num(leadTime[st.key]), 0));
const defaultLeadText = computed(() => {
    if (!defaultLead.value) return '기본값 미등록 — 0일';
    const parts = LEAD_STAGES.map(st => `${st.label} ${defaultLead.value[st.key] ?? '—'}`);
    const total = LEAD_STAGES.reduce((s, st) => s + num(defaultLead.value[st.key]), 0);
    return `${parts.join(' · ')} = ${total}일`;
});

// 대분류 변경 → 소분류가 맞지 않으면 첫 항목으로
const isProduct = computed(() => form.item_type === 'PRODUCT');
const visibleTabs = computed(() => TABS.filter(t => t.key !== 'routing' || isProduct.value));

// 조달 구분 선택지 — 자재 : 구매 · 사급 / 제품 : 해당 없음( 만들어 쓴다 ) · 구매( 사서 쓰는 상품 · 반제품 )
const procureOptions = computed(() => form.item_type === 'MATERIAL'
    ? [{ value: 'BUY', label: '구매' }, { value: 'SUPPLIED', label: '사급' }]
    : [{ value: 'NONE', label: '해당 없음' }, { value: 'BUY', label: '구매' }]);
const procureHint = computed(() => ({
    BUY:      '발주서( 구매관리 )에서 고를 수 있습니다.',
    SUPPLIED: '발주하지 않습니다. 수동 입고로 받습니다.',
    NONE:     '발주서 · 수동 입고에 나오지 않습니다.',
}[form.procure_type] ?? ''));

function onTypeChange(type) {
    // 대분류를 바꾸면 조달 구분 · 판매 여부도 그 대분류 기본값으로
    form.procure_type = type === 'MATERIAL' ? 'BUY' : 'NONE';
    form.is_sale      = type === 'MATERIAL' ? 'N' : 'Y';
    const kinds = ITEM_KINDS[type] ?? [];
    if (!kinds.some(k => k.value === form.item_kind)) {
        form.item_kind = kinds[0]?.value ?? '';
    }

    // 자재 : 공정 탭이 사라진다 ( 서버가 품목 라우팅을 미사용으로 ) / 다시 제품 : 신규면 기본 틀을 다시 불러옴
    if (type !== 'PRODUCT') {
        if (tab.value === 'routing') tab.value = 'basic';
    } else if (!isEdit.value && !form.routing_idx && !routingLines.length && defaultRoutingIdx.value) {
        form.routing_idx = defaultRoutingIdx.value;
        loadTemplate(defaultRoutingIdx.value);
    }
}


// ---- 지시 수량 미리보기 ----
const PREVIEW_NEED = 1400;
const num = (v) => (v === '' || v === null || isNaN(Number(v))) ? 0 : Number(v);
const previewMoq   = computed(() => Math.max(PREVIEW_NEED, num(form.moq)));
const previewOrder = computed(() => {
    const round = num(form.unit_round);
    return round > 0 ? Math.ceil(previewMoq.value / round) * round : previewMoq.value;
});

// ---- 저장 ----
const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

const tabHasError = (key) => Object.keys(errors.value).some(f => tabOf(f) === key);

async function save() {
    saving.value = true;
    errors.value = {};
    errorMsg.value = '';
    try {
        const payload = {
            ...form,
            routing_idx: !isProduct.value || form.routing_idx === '' ? null : Number(form.routing_idx),
            // 공정 탭 : 품목 라우팅( ② ) 공정 줄 ( 자재는 보내지 않음 )
            routing_lines: isProduct.value ? routingLines.map(toLinePayload) : null,
            // 주 자재를 비우면 null → 서버가 BOM 줄을 삭제한다
            main_material: (isProduct.value && !bomLocked.value && mainMaterial.child_item_idx !== '')
                ? {
                    child_item_idx: Number(mainMaterial.child_item_idx),
                    qty: mainMaterial.qty === '' ? null : mainMaterial.qty,
                    calc_code: mainMaterial.calc_code ?? null,
                    ext_n01: mainMaterial.ext_n01 ?? null,
                    mode: mainChanged.value ? mainMaterial.mode : 'REPLACE',
                }
                : null,
            // 탭 4 : 대분류에 맞는 정책만 ( 나머지는 서버가 해제 ). '' = 따름 → null
            policies: Object.fromEntries(
                (policyKeysByType.value[form.item_type] ?? []).map(k => [k, policies[k] === '' || policies[k] === undefined ? null : policies[k]])
            ),
            // 거래처 탭 : 줄 그대로 ( 단가 빈 칸 = null )
            partners: itemPartners.map(({ _rowKey, partner_name, partner_code, ...p }) => ({
                ...p,
                partner_idx: p.partner_idx === '' ? null : Number(p.partner_idx),
                unit_price: p.unit_price === '' || p.unit_price === null ? null : p.unit_price,
            })),
            lead_time: {
                mode: leadTime.mode,
                ...Object.fromEntries(LEAD_STAGES.map(st => [st.key, leadTime[st.key] === '' || leadTime[st.key] === null ? null : Number(leadTime[st.key])])),
            },
        };
        const { data } = await window.axios.post(route('config.item.save'), payload);
        await flushAttach(data.item?.item_idx);
        props.onSaved?.(data.item, data.warnings ?? []);
        emit('close');
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
            // 첫 오류가 있는 탭으로 이동
            const first = Object.keys(errors.value)[0];
            if (first) tab.value = tabOf(first);
        } else if (e.response?.status === 403) {
            errorMsg.value = '저장 권한이 없습니다.';
        } else if (e.response?.status === 404) {
            errorMsg.value = '품목을 찾을 수 없습니다. 목록을 새로고침하세요.';
        } else {
            errorMsg.value = '저장에 실패했습니다.';
        }
    } finally {
        saving.value = false;
    }
}
</script>
