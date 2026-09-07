<div class="form-field form-field-wide pinova-create-customer-modal-wrapper">

	<a
		id="pinova_create_customer_modal_trigger"
		class="pinova-create-customer-modal-trigger-link"
		href="#"
		onclick="event.preventDefault(); pinovaOpenCreateCustomerModal();"
	>
		<?php _e( 'افزودن مشتری جدید &larr;', 'pinova' ); ?>
	</a>

	<section
		id="pinovaCreateCustomerModal"
		class="pinova-container"
		pinova-data="pinovaCreateCustomer"
		pinova-on:keydown.escape.window="modalIsOpen = false"
	>
		<div class="fixed z-[99999] top-0 left-0 flex items-center justify-center w-full h-full overflow-auto custom-scrollbar py-10 px-4"
			pinova-show="modalIsOpen"
			pinova-cloak
		>
			<!-- overlay -->
			<div class="fixed z-10 top-0 left-0 w-full h-full bg-black bg-opacity-50 cursor-pointer"
				pinova-on:click="modalIsOpen = false"
			></div>

			<!-- modal body -->
			<div class="relative bg-white text-gray-900 text-base w-[480px] max-w-full z-20  rounded-xl p-5 my-auto">

				<!-- loader -->
				<div class="absolute top-0 left-0 h-full w-full z-[999] bg-gray-300 bg-opacity-90 flex items-center justify-center p-4"
					pinova-show="pageLoaderIsActive"
					pinova-cloak
				>
					<span class="loader"></span>
				</div>

				<div class="mb-3">
					<div class="size-12 flex items-center justify-center bg-primary-50 rounded-full">
						<div class="size-9 flex items-center justify-center bg-primary-100 rounded-full">
							<img src="<?php echo PINOVA_URL ?>/assets/images/icons/add-circle.svg">
						</div>
					</div>
				</div>

				<div class="font-semibold text-lg mb-1">
					افزودن مشتری جدید
				</div>

				<div class="mb-10">
					<div class="mb-4">
						<label for="pinova_create_customer_first_name" class="block text-sm mb-2">نام </label>
						<div>
							<input
								type="text"
								name="pinova_create_customer_first_name"
								id="pinova_create_customer_first_name"
								class="w-full bg-white border border-gray-300 shadow-[0_1px_2px_0_#1018280D] rounded-lg resize-none py-2 px-3"
								placeholder="نام مشتری جدید را وارد کنید"
								pinova-model="forms.createCustomer.inputs.first_name.value"
								pinova-bind:required="modalIsOpen"
								pinova-bind:class="{'!border-error-300' : forms.createCustomer.inputs.first_name.errorMsg}"
								pinova-on:keydown.enter.prevent.stop="submit()"
							>
						</div>
						<!--error msg-->
						<div class="text-xs text-error-400 pt-1.5 empty:pt-0"
						     pinova-show="forms.createCustomer.inputs.first_name.errorMsg"
						     pinova-text="forms.createCustomer.inputs.first_name.errorMsg"
						></div>
					</div>
					<div class="mb-4">
						<label for="pionva_create_customer_last_name" class="block text-sm mb-2">نام خانوادگی </label>
						<div>
							<input
								type="text"
								name="pionva_create_customer_last_name"
								id="pionva_create_customer_last_name"
								class="w-full bg-white border border-gray-300 shadow-[0_1px_2px_0_#1018280D] rounded-lg resize-none py-2 px-3"
								placeholder="نام خانوادگی  مشتری جدید را وارد کنید"
								pinova-model="forms.createCustomer.inputs.last_name.value"
								pinova-bind:required="modalIsOpen"
								pinova-bind:class="{'!border-error-300' : forms.createCustomer.inputs.last_name.errorMsg}"
								pinova-on:keydown.enter.prevent.stop="submit()"
							>
						</div>
						<!--error msg-->
						<div class="text-xs text-error-400 pt-1.5 empty:pt-0"
						     pinova-show="forms.createCustomer.inputs.last_name.errorMsg"
						     pinova-text="forms.createCustomer.inputs.last_name.errorMsg"
						></div>
					</div>
					<div class="mb-4">
						<label for="pionva_create_customer_mobile" class="block text-sm mb-2">تلفن همراه</label>
						<div>
							<input
								type="number"
								name="pionva_create_customer_mobile"
								id="pionva_create_customer_mobile"
								class="pinova-ltr-input-rtl-placeholder w-full bg-white border border-gray-300 shadow-[0_1px_2px_0_#1018280D] rounded-lg resize-none py-2 px-3"
								placeholder="تلفن همراه مشتری جدید را وارد کنید"
								pinova-model="forms.createCustomer.inputs.mobile.value"
								pinova-bind:required="modalIsOpen"
								pinova-bind:class="{'!border-error-300' : forms.createCustomer.inputs.mobile.errorMsg}"
								pinova-on:keydown.enter.prevent.stop="submit()"
							>
						</div>
						<!--error msg-->
						<div class="text-xs text-error-400 pt-1.5 empty:pt-0"
						     pinova-show="forms.createCustomer.inputs.mobile.errorMsg"
						     pinova-text="forms.createCustomer.inputs.mobile.errorMsg"
						></div>
					</div>
					<div class="mb-4">
						<label for="pionva_create_customer_email" class="block text-sm mb-2">ایمیل (اختیاری)</label>
						<div>
							<input
								type="email"
								name="pionva_create_customer_email"
								id="pionva_create_customer_email"
								class="pinova-ltr-input-rtl-placeholder w-full bg-white border border-gray-300 shadow-[0_1px_2px_0_#1018280D] rounded-lg resize-none py-2 px-3"
								placeholder="ایمیل مشتری جدید را وارد کنید (اختیاری)"
								pinova-model="forms.createCustomer.inputs.email.value"
								pinova-bind:class="{'!border-error-300' : forms.createCustomer.inputs.email.errorMsg}"
								pinova-on:keydown.enter.prevent.stop="submit()"
							>
						</div>
						<!--error msg-->
						<div class="text-xs text-error-400 pt-1.5 empty:pt-0"
						     pinova-show="forms.createCustomer.inputs.email.errorMsg"
						     pinova-text="forms.createCustomer.inputs.email.errorMsg"
						></div>
					</div>
				</div>

				<div class="flex sm:flex-nowrap flex-wrap justify-center gap-3">
					<button type="button"
					        class="sm:w-1/2 w-full border border-gray-300 text-gray-700 font-semibold rounded-lg hover:shadow py-2"
					        pinova-on:click="modalIsOpen = false"
					>
						انصراف
					</button>
					<button type="button"
					        class="flex justify-center items-center sm:w-1/2 w-full border bg-primary-600 border-primary-600 text-white font-semibold rounded-lg hover:shadow py-2"
					        pinova-on:click="submit()"
					>
						افزودن
					</button>
				</div>

			</div>

		</div>

	</section>

</div>

<style>
    .pinova-ltr-input-rtl-placeholder {
        direction: ltr;
        text-align: left;
    }

    .pinova-ltr-input-rtl-placeholder::placeholder {
        direction: rtl;
        text-align: right;
    }

    .pinova-create-customer-modal-trigger-link {
        padding: 3px 0 0;
    }
</style>