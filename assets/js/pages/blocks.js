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
                    label: "مسدود تا",
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

                this.pagination = {
                    currentPage: data.current_page,
                    totalPage: parseInt((data.total_items / this. tableFilters.per_page)) + 1,
                    items: pinovaGetVisiblePages({
                        currentPage: data.current_page,
                        totalPage: parseInt((data.total_items / this. tableFilters.per_page)) + 1
                    })
                }

                this.skeletonIds = [];
                for (const item of this.tableData) {
                    this.skeletonIds.push(item.id)
                }
            }else{
                pinovaNotyf.error(result.message ? result.message : 'حطایی رخ داده است!');
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
                    const data = result.data;
                    this.blockedBy.users = result.data.users.map(user=>{
                        return {
                            id: user.data.ID,
                            name: user.data.display_name
                        }
                    });
                }else{
                    pinovaNotyf.error(result.message ? result.message : 'حطایی رخ داده است!');
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
                const data = result.data;
                pinovaNotyf.success(result.message ? result.message : 'درخواست با موفقیت انجام شد!');

                this.modals.add.active = false;
                this.skeletonIds.push(data.block_id);
                this.tableLoaderIsActive = true;
                this.tableData = data.blocks;
                setTimeout(()=>{
                    this.tableLoaderIsActive = false;
                }, 1000)
            }else{
                pinovaNotyf.error(result.message ? result.message : 'حطایی رخ داده است!');
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
                const data = result.data;
                this.tableData = data.blocks;
                pinovaNotyf.success(result.message ? result.message : 'درخواست با موفقیت انجام شد!');
                this.modals.delete.active = false;
            }else{
                pinovaNotyf.error(result.message ? result.message : 'حطایی رخ داده است!');
                this.skeletonIds.push(this.modals.delete.block.id);
            }
            this.modals.delete.loaderIsActive = false;

        }catch (error){
            console.error('Error fetching posts:', error);
            this.modals.delete.loaderIsActive = false;
            this.skeletonIds.push(this.modals.delete.block.id);
        }
    },

    changePage(newPage){
        this.pagination.currentPage = newPage;
        this.tableFilters.page = newPage;
        this.getUsers();
    },

    openAddModal(data){
        this.modals.add.active = true;
        this.modals.add.data = {
            blocked_type: {
                label: "مسدود تا",
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

        this.getBlocks();
    },

    selectBlockedBy(select){
        this.blockedBy.selected = select;
        this.tableFilters.blocked_by = Number(select.id);
    }

}))
pinovaAlpine.start();