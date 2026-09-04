<?php

use MapasCulturais\i;

/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */
$this->import("
    mc-modal
    mc-alert
    mc-icon
    mc-loading
");
?>

<div class="valuers-management">
    <mc-modal :title="modalTitle">
        <template #default>
            <mc-alert v-if="evaluationNotStarted" type="warning">
                <p><?php i::_e('A fase de avaliação ainda não foi iniciada.') ?></p>
                <p><?php i::_e('Por isso, a importação da distribuição de avaliações não está disponível no momento. A funcionalidade ficará disponível automaticamente assim que a fase de avaliação começar.') ?></p>
            </mc-alert>
            <template v-else>
            <template v-if="!hasPendingFile">
                <mc-alert type="warning">
                    <p><?php i::_e('A planilha deve conter obrigatoriamente as seguintes colunas:') ?></p>
                    <ul>
                        <li>
                            <small>
                                <strong><?php i::_e('INSCRICAO: ') ?></strong>
                                <small><?php i::_e('Número da inscrição que será avaliada Ex.: on-99999999') ?></small>
                            </small>
                        </li>
                        <li>
                            <small>
                                <strong><?php i::_e('AGENTE: ') ?></strong>
                                <small><?php i::_e('ID do agente avaliador responsável pela avaliação Ex.: 99999999') ?></small>
                            </small>
                        </li>
                    </ul>
                    <br>
                    <p>
                        <strong>
                            <?php i::_e('Atenção: na coluna AGENTE, utilize sempre o ID do agente avaliador. Não utilize o ID do usuário.') ?>
                        </strong>
                    </p>
                </mc-alert>

                <div class="valuers-management__sample">
                    <p>
                        <a href="<?= $app->createUrl('opportunity', 'sample-ValuersManagement') ?>">
                            <mc-icon name="download"></mc-icon>
                            <?php i::_e('Baixar modelo de planilha') ?>
                        </a>
                    </p>
                </div>
            </template>

            <template v-if="hasPendingFile">
                <mc-alert type="helper">
                    <p>
                        <?php i::_e('Ótimo! O upload do arquivo') ?>
                        <strong><i>{{ pendingFile.name }}</i></strong>
                        <?php i::_e('foi realizado com sucesso!') ?>
                    </p>
                    <p>
                        <?php i::_e('Agora escolha o modo de importação e clique em "Processar" para realizar a atribuição das avaliações.') ?>
                    </p>
                </mc-alert>

                <div class="valuers-management__mode">
                    <p class="valuers-management__mode-title">
                        <strong><?php i::_e('Modo de importação') ?></strong>
                    </p>

                    <div class="valuers-management__mode-options">
                        <label class="valuers-management__mode-option">
                            <input type="radio" name="importMode" value="complement" v-model="importMode">
                            <span><?php i::_e('Complementar') ?></span>
                        </label>

                        <label class="valuers-management__mode-option">
                            <input type="radio" name="importMode" value="replace" v-model="importMode">
                            <span><?php i::_e('Substituir') ?></span>
                        </label>
                    </div>

                    <div class="valuers-management__mode-help">
                        <div class="valuers-management__mode-help-item">
                            <small>
                                <strong><?php i::_e('Complementar: ') ?></strong>
                                <?php i::_e('mantém os avaliadores atuais da comissão e adiciona os novos da planilha.') ?>
                            </small>
                        </div>
                        <div class="valuers-management__mode-help-item">
                            <small>
                                <strong><?php i::_e('Substituir: ') ?></strong>
                                <?php i::_e('remove os avaliadores atuais da comissão e utiliza apenas os novos da planilha.') ?>
                            </small>
                        </div>
                    </div>
                </div>
            </template>

            <div class="valuers-management__pending">
                <label class="valuers-management__pending-title">
                    <?php i::_e('Planilha a processar') ?>
                </label>

                <div v-if="!hasPendingFile" class="valuers-management__pending-upload">
                    <p class="valuers-management__pending-empty">
                        <?php i::_e('Nenhuma planilha enviada para esta comissão.') ?>
                    </p>
                    <mc-modal :title="text('Enviar planilha')" classes="valuers-management__upload-modal">
                        <mc-loading :condition="uploadLoading"></mc-loading>
                        <template v-if="!uploadLoading" #default>
                            <div class="field valuers-management__upload-field">
                                <label><?php i::_e('Anexe a planilha da comissão') ?></label>
                                <div class="field__upload">
                                    <label class="field__buttonUpload button button--icon button--primary-outline">
                                        <mc-icon name="upload"></mc-icon>
                                        <?php i::_e('Selecionar arquivo') ?>
                                        <input
                                            type="file"
                                            :accept="uploadAccept"
                                            @change="setUploadFile"
                                        >
                                    </label>
                                </div>
                                <small v-if="selectedUploadFile" class="valuers-management__upload-selected">
                                    {{ selectedUploadFile.name }}
                                </small>
                            </div>
                        </template>
                        <template #button="uploadModal">
                            <button
                                type="button"
                                class="button button--primary button--icon button--primary-outline button-up"
                                @click="uploadModal.open()"
                            >
                                <mc-icon name="upload"></mc-icon>
                                {{ text('Enviar planilha') }}
                            </button>
                        </template>
                        <template v-if="!uploadLoading" #actions="uploadModal">
                            <button type="button" class="button button--text" @click="uploadModal.close()">
                                <?php i::_e('Cancelar') ?>
                            </button>
                            <button
                                type="button"
                                class="button button--primary"
                                :disabled="!selectedUploadFile"
                                @click="submitUpload(uploadModal)"
                            >
                                <?php i::_e('Enviar') ?>
                            </button>
                        </template>
                    </mc-modal>
                </div>

                <div v-else class="valuers-management__pending-file">
                    <a class="valuers-management__pending-link" :href="pendingFile.url" :download="pendingFile.name">
                        <mc-icon name="download"></mc-icon>
                        <span>{{ pendingFile.name }}</span>
                    </a>
                    <button
                        type="button"
                        class="valuers-management__pending-remove"
                        :title="text('Remover planilha')"
                        :aria-label="text('Remover planilha')"
                        :disabled="deleting"
                        @click="deletePending"
                    >
                        <mc-icon name="trash"></mc-icon>
                    </button>
                </div>
            </div>

            <div v-if="historyFiles.length" class="valuers-management__history">
                <h6 class="valuers-management__history-title">
                    <?php i::_e('Histórico de importações') ?>
                </h6>
                <ul class="valuers-management__history-list">
                    <li v-for="item in historyFiles" :key="item.file.id" class="valuers-management__history-item">
                        <a class="valuers-management__history-link" :href="item.file.url" :download="item.file.name">
                            <mc-icon name="download"></mc-icon>
                            <span>{{ item.file.name }}</span>
                        </a>
                        <small class="valuers-management__history-meta">
                            <span v-if="item.meta.processed_at">{{ formatProcessedAt(item.meta.processed_at) }}</span>
                            <span v-if="item.meta.mode"> · {{ modeLabel(item.meta.mode) }}</span>
                            <span v-if="item.meta.rows_total"> · {{ item.meta.rows_total }} <?php i::_e('linhas') ?></span>
                        </small>
                    </li>
                </ul>
            </div>
            </template>
        </template>

        <template #button="modal">
            <button class="button button--primary-outline button--large" @click="modal.open()">
                <?php i::_e('Importar distribuição de avaliações') ?>
            </button>
        </template>

        <template #actions="modal">
            <div :class="hasPendingFile ? 'col-6' : 'col-12'">
                <button class="button button--text button--large button--md" @click="modal.close()">
                    <?php i::_e('Cancelar') ?>
                </button>
            </div>
            <div v-if="hasPendingFile && !evaluationNotStarted" class="col-6">
                <button
                    class="button button--primary button--large button--md"
                    @click="processFile(modal)"
                >
                    <mc-icon class="valuers-management__button-icon" name="process"></mc-icon>
                    <?php i::_e('Processar') ?>
                </button>
            </div>
        </template>
    </mc-modal>
</div>
