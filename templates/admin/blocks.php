<?php

defined( 'ABSPATH' ) || exit;

wp_enqueue_style('custom-style', PINOVA_URL . 'assets/css/style.css', [], PINOVA_VERSION);
wp_enqueue_style('notyf-style', PINOVA_URL . 'assets/css/notyf.min.css', [], PINOVA_VERSION);
wp_enqueue_style( 'persian-datepicker-style', PINOVA_URL . 'assets/css/persian-datepicker.min.css', [], PINOVA_VERSION );

wp_enqueue_script( 'persian-datepicker-script', PINOVA_URL . 'assets/js/persian-datepicker.min.js', ['jquery'], PINOVA_VERSION, true );
wp_enqueue_script( 'persian-date-script', PINOVA_URL . 'assets/js/persian-date.min.js', ['jquery'], PINOVA_VERSION, true );

wp_enqueue_script('notyf-script', PINOVA_URL . 'assets/js/notyf.min.js', [], PINOVA_VERSION, true);
wp_enqueue_script('global-script', PINOVA_URL . 'assets/js/global.js', ['notyf-script'], PINOVA_VERSION, true);
wp_enqueue_script('pinova-blocks', PINOVA_URL . 'assets/js/pages/blocks.js', ['global-script'], PINOVA_VERSION, ['type' => 'module']);

wp_localize_script( 'global-script', 'pinova', [
	'root'  => esc_url_raw( rest_url() ),
	'nonce' => wp_create_nonce( 'wp_rest' ),
    'adminPage' => true
] );
?>

<style>
    body, html {
        background-color: #F9FBFE;
    }
</style>

<section  class="pinova-container">
	<section  pinova-data="blocks()" class="text-gray-900 text-base py-6 md:pl-5 pl-2.5">
		<div class="container">

			<div class="flex items-center gap-2 flex-wrap mb-5">
				<div class="font-semibold text-lg ml-auto">
                    مسدودی‌ها
				</div>
				<div pinova-show="!tableLoaderIsActive">
                    <button pinova-on:click="openAddModal()" class="bg-primary-500 hover:bg-primary-600 flex items-center gap-2 text-sm text-white hover:text-white rounded-[8px] py-2 px-3.5">
                        <img class="" src="<?php echo PINOVA_URL ?>assets/images/icons/add.svg">
                        <span>افزودن مسدودی جدید</span>
                    </button>
                </div>
			</div>

            <!-- search -->
            <div class="bg-white border border-gray-300 rounded-xl mb-5">
                <!-- skeleton -->
                <template pinova-if="tableLoaderIsActive">
                    <div class="grid md:grid-cols-11 grid-cols-12 lg:gap-2 gap-4 md:p-6 p-4">
                        <template pinova-for="item in [1,2,3]">
                            <div class="lg:col-span-3 col-span-full">
                                <div class="skeleton w-20 h-5 mb-2 rounded-lg"></div>
                                <div class="skeleton h-11 rounded-lg"></div>
                            </div>
                        </template>
                        <div class="lg:col-span-2 col-span-full">
                            <div class="lg:block hidden w-20 h-5 mb-2"></div>
                            <div class="skeleton h-11 rounded-lg"></div>
                        </div>
                    </div>
                </template>

                <div
                        pinova-show="!tableLoaderIsActive"
                        pinova-cloak
                        class="grid lg:grid-cols-11 grid-cols-12 lg:gap-2 gap-4 md:p-6 p-4"
                >
                    <div class="lg:col-span-3 col-span-full">
                        <div>
                            <label class="block text-sm mb-2">شناسه</label>
                            <input pinova-model="tableFilters.identifier" type="text" class="w-full bg-white border !border-gray-300 shadow-[0_1px_2px_0_#1018280D] !rounded-lg !py-2 !px-3" placeholder="شناسه را وارد کنید">
                        </div>
                    </div>

                    <div class="lg:col-span-3 col-span-full">
                        <div>
                            <label class="block text-sm mb-2">مسدود شده توسط</label>
                            <input pinova-model="tableFilters.blocked_by" type="text" class="w-full bg-white border !border-gray-300 shadow-[0_1px_2px_0_#1018280D] !rounded-lg !py-2 !px-3" placeholder="جستجو کنید">
                        </div>
                    </div>

                    <!-- hide -->
                    <div class="hidden lg:col-span-3 col-span-full">
                        <div>
                            <label class="block text-sm mb-2">مسدود شده توسط</label>

                            <div class="gap-1 border border-gray-300 shadow-[0_1px_2px_0_#1018280D] bg-white rounded-lg">
                                <!-- select dropdown -->
                                <div
                                        pinova-data="{open: false, value: null}"
                                        pinova-on:click.outside="open = false"
                                        class="relative h-full"
                                >
                                    <div class="flex items-center gap-2 py-2 px-3">

                                        <input
                                                pinova-on:keyup="searchBlockedBy($el.value); open = true"
                                                pinova-model="value"
                                                placeholder="جستجو کنید"
                                                class="w-full"
                                        >
                                    </div>

                                    <!-- dropdown items-->
                                    <div
                                            class="max-h-0 w-[calc(100%+2px)] absolute z-[1] top-[calc(100%+4px)] -left-[1px] border border-gray-200 border-opacity-0 rounded overflow-auto custom-scrollbar duration-300"
                                            pinova-bind:class="{'!max-h-40 !border-opacity-100 shadow bg-white z-[2]' : open}"
                                    >
                                        <div class="bg-white pt-0.5">
                                            <template pinova-if="!blockedBy.loader">
                                                <template pinova-for="(item, index) in blockedBy.users">
                                                    <div
                                                            pinova-on:click="selectBlockedBy(item); value = item.name ; open = false"
                                                            class="flex gap-2 items-center cursor-pointer hover:text-primary-300 duration-300 p-1.5 mx-1"
                                                            pinova-bind:class="{'border-b' : (index+1 !== blockedBy.users.length)}"
                                                    >
                                                        <span pinova-text="item.name"></span>
                                                        <div
                                                                pinova-show="item.id === blockedBy.selected?.id"
                                                                class="text-primary-300 mr-auto"
                                                        >
                                                            <svg width="10" viewBox="0 0 18 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                                <path d="M17 1L6 12L1 7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                                            </svg>
                                                        </div>
                                                    </div>
                                                </template>
                                            </template>

                                            <!-- notfound -->
                                            <template pinova-if="!blockedBy.loader && blockedBy.users.length === 0">
                                                <div class="text-error-300 text-center text-sm p-2">موردی یافت نشد</div>
                                            </template>

                                            <!-- loader -->
                                            <template pinova-if="blockedBy.loader">
                                                <div class="flex items-center justify-center text-sm gap-2 p-2">
                                                    در حال جستجو
                                                    <div class="rotation-animation size-4">
                                                        <img class="h-full" src="<?php echo PINOVA_URL ?>assets/images/icons/refresh.svg">
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!--error msg-->
                            <div class="text-xs text-error-300 pt-1.5 empty:pt-0"></div>
                        </div>
                    </div>

                    <div class="lg:col-span-3 col-span-full">
                        <label class="block text-sm mb-2">تاریخ</label>
                        <!-- date -->
                        <div id="rangeDateFilter">
                            <div
                                    pinova-on:click="modals.rangeDate.active = true"
                                    class="filter-range-date flex items-center border border-gray-300 shadow-[0_1px_2px_0_#1018280D] rounded-lg duration-300 cursor-pointer bg-white hover:bg-primary-50"
                            >
                                <div class="border-l border-gray-300 min-w-9 p-2">
                                    <img src="<?php echo PINOVA_URL ?>assets/images/icons/calendar.svg">
                                </div>
                                <div class="show-value text-sm font-normal  p-2.5">
                                    <template pinova-if="!tableFilters.from_date && !tableFilters.to_date">
                                        <span class="text-gray-500">انتخاب زمان دلخواه</span>
                                    </template>

                                    <template pinova-if="tableFilters.from_date">
                                        <span class="text-xs">
                                            <span class='text-gray-400'>از</span>
                                            <span pinova-text="pinovaFormatDate(tableFilters.from_date, 'L')"></span>
                                        </span>
                                    </template>
                                    <template pinova-if="tableFilters.to_date">
                                        <span class="text-xs">
                                            <span class='text-gray-400'>تا</span>
                                            <span pinova-text="pinovaFormatDate(tableFilters.to_date, 'L')"></span>
                                        </span>
                                    </template>
                                </div>
                            </div>

                            <!-- Modal Range Date -->
                            <div
                                    pinova-transition
                                    pinova-cloak
                                    class="fixed top-0 left-0 z-10 flex items-center justify-center w-full h-full overflow-auto custom-scrollbar p-4"
                                    pinova-show="modals.rangeDate.active"
                            >
                                <!-- overlay -->
                                <div
                                        pinova-on:click="modals.rangeDate.active = false"
                                        class="fixed z-10 top-0 left-0 w-full h-full bg-black bg-opacity-50 cursor-pointer"
                                ></div>

                                <!-- body modal -->
                                <div class="modal bg-white w-[500px] max-w-full z-20  rounded-xl py-5 my-auto">
                                    <div class="text-xl px-5 mb-5">
                                        فیلتر زمانی
                                    </div>
                                    <div class="flex gap-4 text-sm">
                                        <div class="text-primary-500 border-b border-primary-500 px-5 pb-2">
                                            انتخاب تاریخ
                                        </div>
                                        <button
                                                pinova-on:click="clearDateFilter(); modals.rangeDate.active = false"
                                                class="text-primary-500 border-b border-transparent hover:text-error-300 px-5 pb-2 mr-auto"
                                        >
                                            پاک کردن
                                        </button>
                                    </div>
                                    <div class="border-t border-gray-100 pt-5 px-5">
                                        <div class="range-date grid grid-cols-12 gap-5 mb-5">
                                            <div class="md:col-span-6 col-span-full">
                                                <div class="text-sm text-center font-semibold text-gray-700 border-b border-gray-200 mb-2 pb-2">
                                                    انتخاب تاریخ شروع
                                                </div>
                                                <div class="range-date-from"></div>
                                                <input class="range-date-from-alt hidden" disabled value="1403-09-21">
                                            </div>
                                            <div class="md:col-span-6 col-span-full">
                                                <div class="text-sm text-center font-semibold text-gray-700 border-b border-gray-200 mb-2 pb-2">
                                                    انتخاب تاریخ پایان
                                                </div>
                                                <div class="range-date-to"></div>
                                                <input class="range-date-to-alt hidden" disabled value="1403-09-28">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-center gap-3 px-5">
                                        <button
                                                pinova-on:click="modals.rangeDate.active = false"
                                                class="w-1/2 border border-gray-300 text-gray-700 font-semibold rounded-lg hover:shadow py-2"
                                        >
                                            انصراف
                                        </button>
                                        <button
                                                pinova-on:click="setDateFilter(); modals.rangeDate.active = false"
                                                class="w-1/2 border bg-primary-600 border-primary-600 text-white font-semibold rounded-lg hover:shadow  py-2"
                                        >
                                            اعمال تغییرات
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="lg:col-span-2 col-span-full lg:pt-7">
                        <button
                                pinova-on:click="getBlocks()"
                                class="block w-full border bg-primary-500 hover:bg-primary-600 text-white text-sm shadow-[0_1px_2px_0_#1018280D] rounded-[8px] py-2.5 px-5 mr-auto"
                        >
                            جستجو
                        </button>
                    </div>
                </div>

            </div>

			<!--table-->
			<div class="bg-white border border-gray-300 rounded-xl overflow-hidden mb-5">

				<div class="flex items-center flex-wrap gap-3 p-4">
					<div class="text-lg font-semibold order-first ml-auto">
						لیست مسدودی‌ها
					</div>
				</div>

				<div class="overflow-auto custom-scrollbar">
					<table class="w-full">
						<thead class="text-sm text-gray-600 text-nowrap">
						<tr>
							<td class="bg-gray-100 py-3 px-5">
								شناسه
							</td>
							<td class="bg-gray-100 py-3 px-5">
								مسدود شده توسط
							</td>
							<td class="bg-gray-100 py-3 px-5">
								مسدود تا
							</td>
							<td class="bg-gray-100 text-center py-3 px-5">
								عملیات
							</td>
						</tr>
						</thead>

						<!-- skeleton -->
						<tbody pinova-show="tableLoaderIsActive" class="w-full text-sm text-gray-700">
						    <template pinova-for="row in (skeletonIds.length > 1 ? skeletonIds : [1])">
							<tr class="border-b bg-white border-gray-200">
								<td class="py-4 md:px-5 px-3">
									<div class="skeleton w-20 h-5 rounded-full"></div>
								</td>
								<td class="py-4 md:px-5 px-3">
									<div class="skeleton w-28 h-5 rounded-full"></div>
								</td>
								<td class="py-4 md:px-5 px-3">
									<div class="skeleton w-32 h-5 rounded-full"></div>
								</td>
								<td class="py-4 md:px-5 px-3">
									<div class="skeleton size-7 rounded-lg"></div>
								</td>
							</tr>
						</template>
						</tbody>

						<tbody pinova-show="!tableLoaderIsActive" class="w-full text-sm text-gray-700">
                            <template pinova-for="row in tableData">
                                <tr class="border-b bg-white border-gray-200">
                                    <td class="py-4 md:px-5 px-3">
                                        <span pinova-text="row.identifier"></span>
                                    </td>
                                    <td class="py-4 md:px-5 px-3">
                                        <template pinova-if="row.blocked_by === 'سیستمی'">
                                            <div class="inline-flex items-center gap-2 rounded-full bg-primary-50 text-xs text-nowrap text-primary-500 px-2 py-1">
                                                <div class="size-1.5 bg-primary-500 rounded-full"></div>
                                                <span pinova-text="row.blocked_by"></span>
                                            </div>
                                        </template>
                                        <template pinova-if="row.blocked_by !== 'سیستمی'">
                                            <span pinova-text="row.blocked_by"></span>
                                        </template>
                                    </td>
                                    <td class="py-4 md:px-5 px-3">
                                        <template pinova-if="!row.blocked_until">
                                            <div class="inline-block rounded-full bg-error-50 text-xs text-nowrap text-error-500 px-2 py-1">
                                                همیشه
                                            </div>
                                        </template>
                                        <template pinova-if="row.blocked_until">
                                            <span pinova-text="row.blocked_until"></span>
                                        </template>
                                    </td>
                                    <td class="text-center py-4 md:px-5 px-3">
                                        <button
                                            pinova-on:click="openDeleteModal(row)"
                                            pinova-bind:disabled="row.blocked_by === 'سیستمی'"
                                            class="size-7 inline-flex items-center justify-center rounded hover:shadow hover:bg-error-100 disabled:opacity-40 disabled:hover:bg-transparent disabled:shadow-none"
                                        >
                                            <img src="<?php echo PINOVA_URL ?>assets/images/icons/trash-gray.svg">
                                        </button>
                                    </td>
                                </tr>
                            </template>
						</tbody>
					</table>

                    <div
                            pinova-show="!tableLoaderIsActive && tableData.length < 1"
                            pinova-cloak
                            class="flex flex-col items-center justify-center text-center py-14 px-8"
                    >
                        <div class="mb-3">
                            <div class="size-12 flex items-center justify-center bg-primary-50 rounded-full mx-auto">
                                <div class="size-9 flex items-center justify-center bg-primary-100 rounded-full">
                                    <img class="size-5" src="<?php echo PINOVA_URL ?>assets/images/icons/search-blue.svg">
                                </div>
                            </div>
                        </div>
                        <div class="font-semibold text-gray-900 mb-5">
                            مسدودی یافت نشد
                        </div>
                        <button
                                pinova-on:click="clearFilter()"
                                class="min-w-fit bg-white border border-gray-300 hover:bg-gray-100 text-sm text-gray-700 font-semibold rounded-[8px] text-nowrap py-2 px-3.5"
                        >
                            <span>حذف فیلتر‌ها</span>
                        </button>
                    </div>
				</div>

				<!-- pagination -->
				<div
					pinova-show="tableData.length > 0"
					class="flex items-center justify-end flex-wrap gap-1.5 text-sm text-gray-600 font-normal p-4"
				>

					<!-- next page -->
					<button
						pinova-on:click="changePage(pagination.currentPage - 1)"
						class="sm:size-7 size-6 flex items-center justify-center border border-gray-200 hover:bg-gray-100 rounded-md rotate-180 disabled:opacity-50"
						pinova-bind:disabled="((pagination.totalPage - (pagination.totalPage - 1)) === pagination.currentPage)"
					>
						<img src="<?php echo PINOVA_URL ?>assets/images/icons/perv.svg">
					</button>

					<template pinova-for="(pageNumber, index) in pagination.items">
						<div>
							<template pinova-if="pageNumber !== '...'">
								<button
									pinova-on:click="changePage(pageNumber)"
									class="sm:size-7 size-6 sm:min-w-7 min-w-6 flex items-center justify-center border border-gray-200 hover:bg-gray-100 rounded-md leading-none pt-0.5 px-1"
									pinova-bind:class="{'border-primary-500 text-primary-500' : (pageNumber === pagination.currentPage)}"
								>
									<span pinova-text="pageNumber"></span>
								</button>
							</template>
							<template pinova-if="pageNumber === '...'">
								<span>...</span>
							</template>
						</div>
					</template>

					<!-- prev page -->
					<button
						pinova-on:click="changePage(pagination.currentPage + 1)"
						class="sm:size-7 size-6 flex items-center justify-center border border-gray-200 hover:bg-gray-100 rounded-md disabled:opacity-50"
						pinova-bind:disabled="(pagination.totalPage === pagination.currentPage)"
					>
						<img src="<?php echo PINOVA_URL ?>assets/images/icons/perv.svg">
					</button>

				</div>

			</div>

			<!-- add card modal -->
			<div
				pinova-transition
				pinova-cloak
				class="fixed z-[99999] top-0 left-0 flex items-center justify-center w-full h-full overflow-auto custom-scrollbar py-10 px-4"
				pinova-show="modals.add.active"
			>
				<!-- overlay -->
				<div
					pinova-on:click="modals.add.active = false"
					class="fixed z-10 top-0 left-0 w-full h-full bg-black bg-opacity-50 cursor-pointer"
				></div>

				<!-- modal body -->
				<div class="bg-white text-gray-900 text-base w-[480px] max-w-full z-20  rounded-xl p-5 my-auto">
					<div class="mb-3">
						<div class="size-12 flex items-center justify-center bg-primary-50 rounded-full">
							<div class="size-9 flex items-center justify-center bg-primary-100 rounded-full">
								<img src="<?php echo PINOVA_URL ?>assets/images/icons/add-circle.svg">
							</div>
						</div>
					</div>
					<div class="font-semibold text-lg mb-1">
						افزودن مسدودی جدید
					</div>
					<div class="text-sm text-gray-600 mb-6">
						شما در حال اضافه کردن یک مسدودی جدید هستید. آیا از این کار اطمینان دارید؟‌
					</div>

                    <div class="mb-10">
                        <div class="mb-5">
                            <label class="block text-sm mb-2">
                                نوع شناسه
                            </label>
                            <div class="gap-1 border border-gray-300 shadow-[0_1px_2px_0_#1018280D] bg-white rounded-lg">
                                <!--dropdown-->
                                <div
                                        pinova-data="{open: false}"
                                        pinova-on:click.outside="open = false"
                                        class="relative h-full"
                                >
                                    <!--active value-->
                                    <div
                                            pinova-on:click="open = !open"
                                            class="flex items-center gap-2 cursor-pointer py-2 px-3"
                                    >
                                        <template pinova-if="modals.add.data.blocked_type.value">
                                            <div
                                                    pinova-text="modals.add.data.blocked_type.value.key"
                                                    class="min-w-10 line-clamp-1">
                                            </div>
                                        </template>
                                        <template pinova-if="!modals.add.data.blocked_type.value">
                                            <div class="min-w-10 line-clamp-1">
                                                انتخاب نوع شناسه
                                            </div>
                                        </template>

                                        <div
                                                class="duration-300 mr-auto"
                                                pinova-bind:class="{'rotate-180' : open}"
                                        >
                                            <svg width="12" height="8" viewBox="0 0 12 8" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M1 1.5L6 6.5L11 1.5" stroke="#667085" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </div>
                                    </div>

                                    <!-- dropdown items-->
                                    <div
                                            class="max-h-0 w-[calc(100%+2px)] absolute z-[1] top-[calc(100%+4px)] -left-[1px] border border-gray-200 border-opacity-0 rounded overflow-auto custom-scrollbar duration-300"
                                            pinova-bind:class="{'!max-h-40 !border-opacity-100 shadow bg-white z-[2]' : open}"
                                    >
                                        <div class="bg-white pt-0.5">
                                            <template pinova-for="(item, index) in blockedTypes">
                                                <div
                                                        pinova-on:click="modals.add.data.blocked_type.value = item; open = !open"
                                                        class="flex gap-2 items-center cursor-pointer hover:text-primary-300 duration-300 p-1.5 mx-1"
                                                        pinova-bind:class="{'border-b' : (index+1 !==  blockedTypes.length)}"
                                                >
                                                    <span pinova-text="item.key"></span>
                                                    <div
                                                            class="text-primary-300 mr-auto"
                                                            pinova-bind:class="{'opacity-0': !(modals.add.data.blocked_type.value?.value === item.value) }"
                                                    >
                                                        <svg width="10" viewBox="0 0 18 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                            <path d="M17 1L6 12L1 7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!--error msg-->
                            <div class="text-xs text-error-300 pt-1.5 empty:pt-0"></div>
                        </div>
                        <div class="mb-5">
                            <label class="block text-sm mb-2" pinova-text="modals.add.data.identifier.label"></label>
                            <div>
                                <input
                                        pinova-model="modals.add.data.identifier.value"
                                        class="w-full bg-white border border-gray-300 shadow-[0_1px_2px_0_#1018280D] rounded-lg resize-none py-2 px-3"
                                        placeholder="مقدار را مشخص کنید"
                                >
                            </div>
                            <!--error msg-->
                            <div
                                    pinova-text="modals.add.data.identifier.errorMsg"
                                    class="text-xs text-error-300 pt-1.5 empty:pt-0"
                            >
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm mb-2" pinova-text="modals.add.data.blocked_until.label"></label>
                            <div class="mb-4">
                                <div
                                        pinova-on:click="modals.add.data.blocked_until.always = false"
                                        class="inline-flex items-center gap-2 cursor-pointer mb-3"
                                >
                                    <div
                                            class="size-5 flex items-center justify-center bg-primary-50 border border-gray-300 rounded-full duration-300"
                                            pinova-bind:class="{'bg-primary-50 !border-primary-600': !modals.add.data.blocked_until.always}"
                                    >
                                        <div
                                                class="size-2 bg-primary-600 rounded-full opacity-0 duration-300"
                                                pinova-bind:class="{'!opacity-100': !modals.add.data.blocked_until.always}"
                                        >
                                        </div>
                                    </div>
                                    انتخاب تاریخ
                                </div>
                                <div
                                        pinova-on:click="openDatePickerModal()"
                                        class="relative z-[1]"
                                >
                                    <div
                                            pinova-show="!modals.add.data.blocked_until.always"
                                            class="absolute z-10 h-full w-full left-0 top-0 cursor-pointer"
                                    >
                                    </div>
                                    <div
                                            class="flex w-full bg-white border border-gray-300 shadow-[0_1px_2px_0_#1018280D] rounded-lg resize-none"
                                            pinova-bind:class="{'!bg-gray-50': modals.add.data.blocked_until.always}"
                                    >
                                        <div class="size-10 min-w-10 flex items-center justify-center border-l border-gray-300">
                                            <img src="<?php echo PINOVA_URL ?>assets/images/icons/calendar.svg">
                                        </div>
                                        <input
                                                disabled
                                                pinova-bind:value="modals.add.data.blocked_until.value ? pinovaFormatDate(modals.add.data.blocked_until.value, 'L') : null"
                                                placeholder="زمان دلخواه را انتخاب کنید"
                                                class="w-full py-2 px-3"
                                        >
                                    </div>
                                </div>
                                <!--error msg-->
                                <div
                                        pinova-text="modals.add.data.blocked_until.errorMsg"
                                        class="text-xs text-error-300 pt-1.5 empty:pt-0"
                                >
                                </div>
                            </div>
                            <div
                                    pinova-on:click="modals.add.data.blocked_until.always = true; modals.add.data.blocked_until.value = null"
                                    class="inline-flex items-center gap-2 cursor-pointer"
                            >
                                <div
                                        class="size-5 flex items-center justify-center bg-primary-50 border border-gray-300 rounded-full duration-300"
                                        pinova-bind:class="{'bg-primary-50 !border-primary-600': modals.add.data.blocked_until.always}"
                                >
                                    <div
                                            class="size-2 bg-primary-600 rounded-full opacity-0 duration-300"
                                            pinova-bind:class="{'!opacity-100': modals.add.data.blocked_until.always}"
                                    >
                                    </div>
                                </div>
                                همیشه
                            </div>
                        </div>
                    </div>

					<div class="flex sm:flex-nowrap flex-wrap justify-center gap-3">
						<button
							pinova-on:click="modals.add.active = false"
							class="sm:w-1/2 w-full border border-gray-300 text-gray-700 font-semibold rounded-lg hover:shadow py-2"
						>
							انصراف
						</button>
						<button
							pinova-on:click="addBlock()"
                            pinova-bind:disabled="modals.add.loaderIsActive"
							class="flex justify-center items-center sm:w-1/2 w-full border bg-primary-600 border-primary-600 text-white font-semibold rounded-lg hover:shadow py-2"
						>
							<template pinova-if="!modals.add.loaderIsActive">
                                <span>افزودن مسدودی</span>
                            </template>
                            <template pinova-if="modals.add.loaderIsActive">
                                <div class="rotation-animation size-6">
                                    <img class="size-6" src="<?php echo PINOVA_URL ?>assets/images/icons/refresh-white.svg">
                                </div>
                            </template>
						</button>
					</div>
				</div>
			</div>

			<!-- delete card modal -->
			<div
				pinova-transition
				pinova-cloak
				class="fixed top-0 left-0 z-10 flex items-center justify-center w-full h-full overflow-auto custom-scrollbar text-base p-4"
				pinova-show="modals.delete.active"
			>
				<!-- overlay -->
				<div
					pinova-on:click="modals.delete.active = false"
					class="fixed z-10 top-0 left-0 w-full h-full bg-black bg-opacity-50 cursor-pointer"
				></div>

				<!-- body -->
				<div class="bg-white w-[480px] max-w-full z-20  rounded-xl p-5 my-auto">
					<div class="mb-3">
						<div class="size-12 flex items-center justify-center bg-error-50 rounded-full">
							<div class="size-9 flex items-center justify-center bg-error-100 rounded-full">
								<img class="" src="<?php echo PINOVA_URL ?>assets/images/icons/trash-red.svg">
							</div>
						</div>
					</div>
					<div class="font-semibold text-lg mb-1">
						رفع مسدودی
					</div>
					<div class="text-sm text-gray-600 mb-6">
						شما در حال رفع مسدودی
                        <span pinova-text="modals.delete.block?.identifier" class="font-bold"></span>
                        هستید. آیا از این کار اطمینان دارید؟
					</div>
					<div class="flex items-center justify-center gap-3">
						<button
							pinova-on:click="modals.delete.active = false"
							class="w-1/2 border border-gray-300 text-gray-700 font-semibold rounded-lg hover:shadow p-2"
						>
							انصراف
						</button>
						<button
                                pinova-on:click="deleteBlock()"
                                pinova-bind:disabled="modals.delete.loaderIsActive"
                                class="flex justify-center items-center w-1/2 border bg-error-600 border-error-600 text-white font-semibold rounded-lg hover:shadow p-2"
                        >
                            <template pinova-if="!modals.delete.loaderIsActive">
                                <span>حذف مسدودی</span>
                            </template>
                            <template pinova-if="modals.delete.loaderIsActive">
                                <div class="rotation-animation size-6">
                                    <img class="size-6" src="<?php echo PINOVA_URL ?>assets/images/icons/refresh-white.svg">
                                </div>
                            </template>
						</button>
					</div>
				</div>
			</div>

            <!-- datepicker -->
            <div
                    pinova-transition
                    pinova-cloak
                    class="fixed z-[99999] top-0 left-0 flex items-center justify-center w-full h-full overflow-auto custom-scrollbar py-10 px-4"
                    pinova-show="modals.datepicker.active"
            >
                <!-- overlay -->
                <div
                        pinova-on:click="modals.datepicker.active = false"
                        class="fixed z-10 top-0 left-0 w-full h-full bg-black bg-opacity-50 cursor-pointer"
                ></div>

                <!-- modal body -->
                <div class="bg-white text-gray-900 text-base w-[480px] max-w-full z-20  rounded-xl p-5 my-auto">
                    <div class="mb-3">
                        <div class="size-12 flex items-center justify-center bg-primary-50 rounded-full">
                            <div class="size-9 flex items-center justify-center bg-primary-100 rounded-full">
                                <img src="<?php echo PINOVA_URL ?>assets/images/icons/calendar-blue.svg">
                            </div>
                        </div>
                    </div>
                    <div
                            pinova-text="modals.datepicker.title"
                            class="font-semibold text-lg mb-1"
                    ></div>
                    <div
                            pinova-text="modals.datepicker.subtitle"
                            class="text-sm text-gray-600 mb-6"
                    ></div>

                    <div class="mb-5" id="date-picker">
                        <div class="date-picker"></div>
                        <input class="date-picker-alt hidden" disabled value="1403-09-21">
                    </div>

                    <div class="flex sm:flex-nowrap flex-wrap justify-center gap-3">
                        <button
                                pinova-on:click="modals.datepicker.active = false"
                                class="sm:w-1/2 w-full border border-gray-300 text-gray-700 font-semibold rounded-lg hover:shadow py-2"
                        >
                            انصراف
                        </button>
                        <button
                                pinova-on:click="selectDate()"
                                class="flex justify-center items-center sm:w-1/2 w-full border bg-primary-600 border-primary-600 text-white font-semibold rounded-lg hover:shadow py-2"
                        >
                            انتخاب
                        </button>
                    </div>

                </div>
            </div>

		</div>
	</section>
</section>

