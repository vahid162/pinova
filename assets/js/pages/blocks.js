//define Alpine
import pinovaAlpine from './../alpine.min.js';
pinovaAlpine.prefix("pinova-");
pinovaAlpine.data("blocks", ()=>({
    pageLoaderIsActive: false,

    //table data
    tableData: [],
    tableLoaderIsActive: false,
    tableFilters: {
        page: 1,
        per_page: 20,
        identifier: null,
        blocked_by: null,
        from_date: null,
        to_date: null,
    },
    pagination:{
        items: [],
        currentPage: 1,
        totalPage: 0,
    },
    skeletonIds: [1,2,3,4,5],

    //page data
    rangeDateFrom: null,
    rangeDateTo: null,
    datePicker: null,
    blockedBy: {
        users: [],
        selected: null,
        query: '',
        timeout: null,
        loader: false
    },
    blockedTypes: [
        {
            key: 'تلفن همراه',
            value: 'mobile'
        },
        {
            key: 'آی.پی',
            value: 'ip'
        },
        {
            key: 'ایمیل',
            value: 'email'
        },
        {
            key: 'نام کاربری',
            value: 'username'
        },
    ],

    //modals
    modals: {
        add:{
            active: false,
            loaderIsActive: false,
            data:{
                blocked_type: {
                    label: "نوع شناسه",
                    value:  {
                        key: 'تلفن همراه',
                        value: 'mobile'
                    },
                    errorMsg: ""
                },
                identifier: {
                    label: "مقدار",
                    value: null,
                    errorMsg: ""
                },
                blocked_until: {
                    label: "مسدود تا",
                    always: true,
                    value: null,
                    errorMsg: ""
                }
            }
        },
        delete:{
            active: false,
            loaderIsActive: false,
            block: null
        },
        datepicker: {
            active: false,
            title: '',
            subtitle: '',
            select: null //function
        },
        rangeDate:{
            active: false
        }
    },

    async init(){
        let todayDate = new persianDate(); //set today

        const queryString = pinovaParseQueryString(window.location.href);
        delete queryString.page;
        delete queryString.per_page;

        for (const objKey in queryString) {
            if(objKey === 'from_date' || objKey === 'to_date'){
                if(pinovaCheckDateFormatIsValid(queryString[objKey])){
                    this.tableFilters[objKey] = pinovaDateToTimestamp(queryString[objKey]);
                }else{
                    delete queryString[objKey];
                }
            }else{
                this.tableFilters[objKey] = queryString[objKey];
            }
        }

        if(!this.tableFilters.from_date || !this.tableFilters.to_date || (this.tableFilters?.from_date > this.tableFilters?.to_date)){
            delete this.tableFilters.from_date;
            delete this.tableFilters.to_date;
        }

        await this.getBlocks();

        //initial date picker
        const tempFromDate = this.tableFilters.from_date ? this.tableFilters.from_date  : todayDate;
        const tempToDate = this.tableFilters.to_date ? this.tableFilters.to_date : todayDate;
        const [rangeDateFrom, rangeDateTo] = pinovaCreateRangeDateFilter(document.getElementById("rangeDateFilter"), tempFromDate, tempToDate)
        this.rangeDateFrom = rangeDateFrom;
        this.rangeDateTo = rangeDateTo;

        this.datePicker = pinovacCreateDatePicker(document.getElementById("date-picker"), todayDate);
    },

    //request functions
    async getBlocks(){
        this.tableLoaderIsActive = true;

        const filtersObj = pinovaGenerateFiltersObject(this.tableFilters);
        pinovaSetUrlQueryParams('pinova-blocks', filtersObj);

        try{

            const result = await pinovaApiRequest('pinova/admin/blocks/index', {
                method: 'POST',
                data: pinovaGenerateFiltersObject(this.tableFilters)
            })

            if(result.success){
                const data = result.data;
                this.tableData = data.blocks;
                const totalPage = Math.max(1, Number.isInteger(data.total_pages)
                    ? data.total_pages
                    : Math.ceil(data.total_items / this.tableFilters.per_page));

                this.tableFilters.page = data.current_page;
                pinovaSetUrlQueryParams('pinova-blocks', pinovaGenerateFiltersObject(this.tableFilters));

                this.pagination = {
                    currentPage: data.current_page,
                    totalPage,
                    items: pinovaGetVisiblePages({
                        currentPage: data.current_page,
                        totalPage
                    })
                }

                this.skeletonIds = [];
                for (const item of this.tableData) {
                    this.skeletonIds.push(item.id)
                }
            }else{
                pinovaNotyf.error(result.message ? result.message : 'خطایی رخ داده است!');
                this.tableLoaderIsActive = false;
            }
            this.tableLoaderIsActive = false;
        }catch (error){
            console.error('Error fetching posts:', error);
            this.tableLoaderIsActive = false;
        }

    },

    async searchBlockedBy(slug){

        if(this.blockedBy.timeout){
            clearTimeout(this.blockedBy.timeout);
        }

        this.blockedBy.timeout = setTimeout(async ()=>{

            if(!slug){
                this.blockedBy.users = [];
                return
            }

            this.blockedBy.loader = true

            try{
                const result = await pinovaApiRequest('pinova/admin/blocks/filters', {
                    method: 'POST',
                    data:{
                        blocked_by: slug
                    }
                })

                if(result.success){
                    this.blockedBy.users = result.data.users;
                }else{
                    pinovaNotyf.error(result.message ? result.message : 'خطایی رخ داده است!');
                }
                this.blockedBy.loader = false

            }catch (error){
                console.error('Error fetching posts:', error);
                this.blockedBy.loader = false
            }
        }, 500)

    },

    async addBlock(){
        //validation
        let hasError = false;
        for (const key in this.modals.add.data) {
            if(key === 'blocked_until'){
                if(!this.modals.add.data[key].value && !this.modals.add.data[key].always){
                    this.modals.add.data[key].errorMsg = this.modals.add.data[key].label + " نمی تواند خالی باشد. "
                    hasError = true;
                }else{
                    this.modals.add.data[key].errorMsg = "";
                }
            }else {
                if(!this.modals.add.data[key].value){
                    this.modals.add.data[key].errorMsg = this.modals.add.data[key].label + " نمی تواند خالی باشد. "
                    hasError = true;
                }else{
                    this.modals.add.data[key].errorMsg = "";
                }
            }
        }

        if(hasError){
            return
        }

        this.modals.add.loaderIsActive = true;

        try{
            const result = await pinovaApiRequest('pinova/admin/blocks/add', {
                method: 'POST',
                data: {
                    identifier: this.modals.add.data.identifier.value,
                    blocked_until: this.modals.add.data.blocked_until.value ? (this.modals.add.data.blocked_until.value / 1000) : null,
                    blocked_type: this.modals.add.data.blocked_type.value.value
                }
            })

            if(result.success){
                pinovaNotyf.success(result.message ? result.message : 'درخواست با موفقیت انجام شد!');

                this.modals.add.active = false;
                await this.getBlocks();
            }else{
                this.setAddFieldErrors(result);
                pinovaNotyf.error(result.message ? result.message : 'خطایی رخ داده است!');
            }
            this.modals.add.loaderIsActive = false;

        }catch (error){
            console.error('Error fetching posts:', error);
            this.modals.add.loaderIsActive = false;
        }
    },

    async deleteBlock(){
        this.skeletonIds = this.skeletonIds.filter(item => item !== this.modals.delete.block.id);
        this.modals.delete.loaderIsActive = true;

        try{
            const result = await pinovaApiRequest('pinova/admin/blocks/delete', {
                method: 'POST',
                data: {
                    block_id : this.modals.delete.block.id
                }
            })

            if(result.success){
                pinovaNotyf.success(result.message ? result.message : 'درخواست با موفقیت انجام شد!');
                this.modals.delete.active = false;
                await this.getBlocks();
            }else{
                pinovaNotyf.error(result.message ? result.message : 'خطایی رخ داده است!');
                this.skeletonIds.push(this.modals.delete.block.id);
            }
            this.modals.delete.loaderIsActive = false;

        }catch (error){
            console.error('Error fetching posts:', error);
            this.modals.delete.loaderIsActive = false;
            this.skeletonIds.push(this.modals.delete.block.id);
        }
    },

    async changePage(newPage){
        const lastPage = Math.max(1, this.pagination.totalPage);
        const page = Math.min(lastPage, Math.max(1, Number(newPage) || 1));
        this.pagination.currentPage = page;
        this.tableFilters.page = page;
        await this.getBlocks();
    },

    openAddModal(data){
        this.modals.add.active = true;
        this.modals.add.data = {
            blocked_type: {
                label: "نوع شناسه",
                value: {
                    key: 'تلفن همراه',
                    value: 'mobile'
                },
                errorMsg: ""
            },
            identifier: {
                label: "مقدار",
                value: null,
                errorMsg: ""
            },
            blocked_until: {
                label: "مسدود تا",
                always: true,
                value: null,
                errorMsg: ""
            }
        }
    },

    openDeleteModal(data){
        this.modals.delete.active = true;
        this.modals.delete.block = data;
    },

    openDatePickerModal(){
        this.modals.datepicker.active = true;
        this.modals.datepicker.title = 'انتخاب تاریخ';
        this.modals.datepicker.subtitle = "انتخاب تاریخ مسدودی";
        this.modals.datepicker.select = (value)=>{
            this.modals.add.data.blocked_until.value = value;
        }
    },

    setDateFilter(){
        this.tableFilters.from_date = this.rangeDateFrom.getState().selected.unixDate;
        this.tableFilters.to_date = this.rangeDateTo.getState().selected.unixDate;
    },

    clearDateFilter(){
        this.tableFilters.from_date = null;
        this.tableFilters.to_date = null;
    },

    selectDate(){
        this.modals.datepicker.active = false;
        const test = new persianDate(this.datePicker.getState().selected.unixDate);
        this.modals.datepicker.select(test.endOf('day').unix() * 1000);
    },

    clearFilter(){
        this.tableFilters = {
            page: 1,
            per_page: 20,
            identifier: null,
            blocked_by: null,
            from_date: null,
            to_date: null,
        }

        this.blockedBy.selected = null;
        this.blockedBy.query = '';
        this.blockedBy.users = [];

        this.getBlocks();
    },

    selectBlockedBy(select){
        this.blockedBy.selected = select;
        this.blockedBy.query = select ? select.name : '';
        this.tableFilters.blocked_by = select ? Number(select.id) : null;
    },

    setAddFieldErrors(result){
        const params = result?.data?.params || {};

        for (const [field, message] of Object.entries(params)) {
            if (this.modals.add.data[field] && typeof message === 'string') {
                this.modals.add.data[field].errorMsg = message;
            }
        }

        const field = result?.data?.field;
        if (field && this.modals.add.data[field] && !params[field]) {
            this.modals.add.data[field].errorMsg = result.message || 'مقدار واردشده معتبر نیست.';
        }
    }

}))
pinovaAlpine.start();
