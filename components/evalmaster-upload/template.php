<?php

use MapasCulturais\i;

/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */
$this->import("
    entity-file
    mc-modal
    mc-alert
");
?>

<div class="valuers-management">
    <mc-modal :title="modalTitle">
        <template v-if="!loading" #default>
            <template v-if="hasFile">
                <div class="process__title">
                    <mc-alert type="helper">
                        <p>
                            <?php i::_e('Ótimo! O upload do arquivo') ?> <strong><i>{{entityFile?.name}}</i></strong> <?php i::_e('foi realizado com sucesso!') ?>
                        </p>
                        <p>
                            <?php i::_e('Agora escolha o modo de importação e clique em "Processar" para realizar a atribuição das avaliações.') ?>
                        </p>
                    </mc-alert>
                </div>
            </template>

            <template v-if="!hasFile">
                <mc-alert type="warning">
                    <p><?php i::_e('A planilha deve conter obrigatoriamente as seguintes colunas:') ?></p>
                    <ul>
                        <li>
                            <small>
                                <strong><?php i::_e('INSCRICAO: ') ?></strong>
                                <small><?php i::_e('Número da inscrição que será avaliada Ex.: on-99999999') ?></small>
                            </small></li>
                        <li>
                            <small>
                                <strong><?php i::_e('AGENTE: ') ?></strong>
                                <small><?php i::_e('ID do agente avaliador responsável pela avaliação Ex.: 99999999') ?></small>
                            </small>
                        </li>
                    </ul>
                    <br>
                    <p><strong><?php i::_e('Atenção: na coluna AGENTE, utilize sempre o ID do agente avaliador. Não utilize o ID do usuário.') ?></strong></p>
                </mc-alert>
            </template>

            <div v-if="hasFile" class="valuers-management__mode">
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
                            <strong><?php i::_e('Complementar: ') ?></strong><?php i::_e('mantém os avaliadores atuais da comissão e adiciona os novos da planilha.') ?>
                        </small>
                    </div>
                    <div class="valuers-management__mode-help-item">
                        <small>
                            <strong><?php i::_e('Substituir: ') ?></strong><?php i::_e('remove os avaliadores atuais da comissão e utiliza apenas os novos da planilha.') ?>
                        </small>
                    </div>
                </div>
            </div>

            <template v-if="!hasFile">
                <div class="valuers-management__field">
                    <br>
                    <div class="field">
                        <input type="file" name="file" id="fileUpload" @change="setFile" ref="file">
                    </div>
                </div>
                <br>
                <div>
                    <p>
                        <a href="<?= $app->createUrl('opportunity', 'sample-ValuersManagement') ?>">
                            <mc-icon name="download"></mc-icon> <?php i::_e('Baixar modelo de planilha') ?>
                        </a>
                    </p>
                </div>
            </template>
        </template>

        <template v-if="loading" #default>
            <p class="semibold">
                <?= i::__("Carregando") ?> <mc-icon name="loading"></mc-icon>
            </p>
        </template>

        <template #button="modal">
            <button class="button button--primary-outline button--large" @click="modal.open()"><?php i::_e('Importar distribuição de avaliações') ?></button>
        </template>

        <template #actions="modal">
            <div v-if="hasFile">
                <entity-file :entity="entity" groupName="evalmaster" title="" editable :required="false">
                    <template  #button>
                        <div class="valuers-management__process">
                            <div class="process__action">
                                <div>
                                    <a @click="deleteFile()" class="button button--text button--large button--md">
                                        <mc-icon class="valuers-management__button-icon" name="delete"></mc-icon> <?php i::_e("Deletar") ?>
                                    </a>
                                </div>

                                <div>
                                    <a @click="processFile()" class="button button--primary button--large button--md">
                                        <mc-icon class="valuers-management__button-icon" name="process"></mc-icon> <?php i::_e("Processar") ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </template>
                </entity-file>
            </div>
            <div v-if="!hasFile">
                <div class="col-6">
                    <button class="button button--text button--large button--md" @click="modal.close()"><?php i::_e('Cancelar') ?></button>
                </div>
                <div class="col-6">
                    <button class="button button--primary button--large button--md" @click="upload(modal)"><?php i::_e('Enviar') ?></button>
                </div>
            </div>
        </template>
    </mc-modal>
</div>