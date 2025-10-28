
app.component('evalmaster-upload', {
    template: $TEMPLATES['evalmaster-upload'],

    setup(props) {
        const text = Utils.getTexts('evalmaster-upload');
        const messages = useMessages();
        return { text, messages }
    },

    props: {
        entity: {
            type: Entity,
            requered: true
        },
        group: {
            type: String,
            default: 'group-admin'
        },
    },

    data() {
        return {
            hasFile: this.entity.opportunity.files['evalmaster'] ? true : false,
            newFile: {},
            loading: false,
            maxFileSize: $MAPAS.maxUploadSizeFormatted
        }
    },

    computed: {
        modalTitle() {
            return this.text("Distribuir avaliacoes em lote");
        },
        fileName() {
            return this.newFile.name ?? this.text('Selecione um arquivo');
        },
        entityFile() {
            return this.entity.opportunity.files['evalmaster']
        },
    },

    methods: {
        processFile() {
            let api = new API();

            let args = {
                entity: this.entity.opportunity.id,
                file: this.entityFile.id,
                committee: this.group
            };

            let url = Utils.createUrl('opportunity', 'valuersmanagement', args);

            api.GET(url).then(res => res.json()).then(response => {
                this.hasFile = false;
                this.newFile = {};
                this.messages.success(this.text('Arquivo processado com sucesso'));
            });

        },
        deleteFile() {
            this.entityFile.delete();
            this.hasFile = false;
            this.messages.success(this.text('Arquivo deletado com sucesso'));
        },
        setFile() {
            this.newFile = this.$refs.file.files[0];
        },
        upload(modal) {
            this.loading = true;

            let data = {
                group: 'evalmaster',
                description: this.newFile.description
            };

            this.entity.opportunity.upload(this.newFile, data).then((response) => {
                this.newFile = {};
                this.loading = false;
                this.hasFile = true;
                this.messages.success(this.text('Arquivo enviado com sucesso'));
            });
        },
    },
});
