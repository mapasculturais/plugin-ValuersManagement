app.component('evalmaster-upload', {
    template: $TEMPLATES['evalmaster-upload'],

    setup() {
        const text = Utils.getTexts('evalmaster-upload');
        const messages = useMessages();
        return { text, messages };
    },

    props: {
        entity: {
            type: Entity,
            required: true,
        },
        group: {
            type: String,
            default: 'group-admin',
        },
    },

    data() {
        return {
            importMode: 'complement',
            deleting: false,
            uploadLoading: false,
            selectedUploadFile: null,
            allowedMimeTypes: [
                'text/csv',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        };
    },

    computed: {
        modalTitle() {
            return this.text('Distribuição de avaliações via planilha');
        },

        opportunity() {
            return this.entity.opportunity;
        },

        pendingFile() {
            const raw = this.opportunity?.files?.evalmaster ?? null;
            const list = Array.isArray(raw) ? raw : (raw ? [raw] : []);
            // Isolamento por comissão: cada arquivo carrega um JSON em
            // `description` com a comissão a que pertence. Filtramos para
            // que uploads em outra comissão não vazem para a atual.
            const match = list.find((f) => {
                const meta = this.parsePendingMeta(f);
                return meta.committee === this.group;
            });
            return match ?? null;
        },

        hasPendingFile() {
            return !!this.pendingFile;
        },

        historyFiles() {
            const raw = this.opportunity?.files?.['evalmaster-history'] ?? [];
            const list = Array.isArray(raw) ? raw : (raw ? [raw] : []);
            return list
                .map(file => ({ file, meta: this.parseHistoryMeta(file) }))
                .filter(item => item.meta.committee === this.group)
                .sort((a, b) => (b.meta.processed_at ?? '').localeCompare(a.meta.processed_at ?? ''));
        },

        uploadAccept() {
            return this.allowedMimeTypes.join(',');
        },
    },

    watch: {
        group() {
            // Ao trocar de comissão, restaura o modo padrão. O estado de
            // pending/histórico é computed e já reflete a comissão atual.
            this.importMode = 'complement';
        },
    },

    methods: {
        parseHistoryMeta(file) {
            try {
                return JSON.parse(file?.description ?? '') || {};
            } catch (_) {
                return {};
            }
        },

        parsePendingMeta(file) {
            try {
                return JSON.parse(file?.description ?? '') || {};
            } catch (_) {
                return {};
            }
        },

        setUploadFile(event) {
            const file = event.target?.files?.[0] ?? null;
            this.selectedUploadFile = file;
        },

        async submitUpload(modal) {
            if (!this.selectedUploadFile || this.uploadLoading) {
                return;
            }
            this.uploadLoading = true;
            try {
                const description = JSON.stringify({ committee: this.group });
                await this.opportunity.upload(this.selectedUploadFile, {
                    group: 'evalmaster',
                    description,
                });
                this.selectedUploadFile = null;
                this.onUploaded();
                this.messages.success(this.text('Arquivo enviado com sucesso'));
                modal.close();
            } catch (err) {
                console.error('[ValuersManagement] submitUpload failed', err);
                const msg = err?.data?.evalmaster ?? err?.message ?? this.text('Falha ao enviar planilha.');
                this.messages.error(msg);
            } finally {
                this.uploadLoading = false;
            }
        },

        formatProcessedAt(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return iso;
            return d.toLocaleString();
        },

        modeLabel(mode) {
            if (mode === 'replace') return this.text('Substituir');
            return this.text('Complementar');
        },

        async processFile(modal) {
            const file = this.pendingFile;
            if (!file) {
                this.messages.error(this.text('Nenhuma planilha pendente para processar.'));
                return;
            }

            modal.loading(true);

            const api = new API();
            const args = {
                entity: this.opportunity.id,
                file: file.id,
                committee: this.group,
                mode: this.importMode,
            };
            const url = Utils.createUrl('opportunity', 'valuersmanagement', args);

            try {
                const response = await api.GET(url);
                let body = {};
                try {
                    body = await response.json();
                } catch (_) {
                    body = {};
                }

                if (!response.ok || body.success === false) {
                    const msg = (body && body.message)
                        ? body.message
                        : this.text('Falha ao processar planilha.');
                    throw new Error(msg);
                }

                this.messages.success(
                    body.message || this.text('Arquivo processado com sucesso')
                );
                modal.close();
                // Garante que a UI da comissão reflita os novos avaliadores e
                // que o slot da planilha pendente seja liberado. Como a página
                // mistura render PHP + componentes Vue, o reload é o caminho
                // mais confiável para refletir as duas camadas.
                setTimeout(() => window.location.reload(), 600);
            } catch (err) {
                console.error('[ValuersManagement] processFile failed', err);
                this.messages.error(err.message || this.text('Falha ao processar planilha.'));
            } finally {
                modal.loading(false);
            }
        },

        onUploaded() {
            // Hook após upload da planilha. O entity-file já atualiza
            // entity.files['evalmaster']. Reseta o modo de importação.
            this.importMode = 'complement';
        },

        async deletePending() {
            if (!this.pendingFile || this.deleting) {
                return;
            }
            this.deleting = true;
            try {
                await this.pendingFile.delete();
                this.importMode = 'complement';
                this.messages.success(this.text('Arquivo deletado com sucesso'));
            } catch (err) {
                console.error('[ValuersManagement] deletePending failed', err);
                this.messages.error(this.text('Falha ao deletar o arquivo.'));
            } finally {
                this.deleting = false;
            }
        },
    },
});
