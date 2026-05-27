<?php

namespace ValuersManagement;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

class Plugin extends \MapasCulturais\Plugin
{
    public const IMPORT_MODE_COMPLEMENT = "complement";
    public const IMPORT_MODE_REPLACE = "replace";

    public const FILE_GROUP_PENDING = "evalmaster";
    public const FILE_GROUP_HISTORY = "evalmaster-history";

    public function _init()
    {
        $app = App::i();

        $app->view->enqueueStyle(
            "app-v2",
            "ValuersManagement-v2",
            "css/plugin-ValuersManagement.css",
        );

        $self = $this;

        // Workaround: o controller de upload do core (POST_upload) só persiste
        // `description` quando o FileGroup é unique=true. Como nosso grupo
        // "evalmaster" aceita múltiplos arquivos (um por comissão), o committee
        // enviado no description pelo frontend seria descartado. Esse hook
        // captura o description vindo do request e o injeta no File antes do
        // save, mantendo o isolamento por comissão.
        $app->hook("entity(OpportunityFile).upload.filesSave:before", function ($file) {
            if ($file->group !== Plugin::FILE_GROUP_PENDING) {
                return;
            }
            $data = $this->data ?? [];
            if (!is_array($data)) {
                return;
            }
            $description = $data["description"] ?? null;
            if (is_array($description) && isset($description[$file->group])) {
                $file->description = $description[$file->group];
            }
        });

        // Endpoint para download do modelo de planilha
        $app->hook("GET(opportunity.sample-ValuersManagement)", function () {

            $this->requireAuthentication();

            $file = __DIR__ . "/files/sample-ValuersManagement.xlsx";
        
            if (!is_file($file)) {
                http_response_code(404);
                exit("Arquivo não encontrado.");
            }
        
            // Limpa qualquer buffer de saída
            if (ob_get_level()) {
                ob_end_clean();
            }
        
            $filename = basename($file);
        
            header('Content-Description: File Transfer');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));
        
            readfile($file);
            exit;
        });

        $app->hook(
            "component(opportunity-evaluation-committee).select-entity:end",
            function () {
                $entity = $this->controller->requestedEntity;
                $this->part("evalmaster--upload", ["entity" => $entity]);
            },
        );

        $app->hook("GET(opportunity.valuersmanagement)", function () use (
            $self,
            $app,
        ) {
            ini_set("max_execution_time", "0");
            ini_set("memory_limit", "4096M");
            $this->requireAuthentication();

            $opportunity = $app
                ->repo("Opportunity")
                ->find($this->data["entity"]);
            if (!$opportunity) {
                $app->log->error(
                    "[ValuersManagement] Oportunidade não encontrada",
                );
                $app->pass();
            }

            $opportunity->checkPermission("@control");

            $request = $this->data;
            $self->pluginLog(
                "[Hook] Requisição recebida: " . json_encode($request),
            );

            $result = $self->valuersmanagement($request);
            $this->json($result, $result["success"] ? 200 : 400);
        });
    }

    protected function pluginLog(string $message): void
    {
        // Em testes (PHPUnit), o handler do Monolog escreve em STDERR e
        // qualquer saída durante o teste é considerada falha — silencia.
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
            return;
        }
        $app = App::i();
        if (!isset($app->log) || !is_object($app->log)) {
            return;
        }
        try {
            $app->log->info("[ValuersManagement] " . $message);
        } catch (\Throwable $e) {
            // Logger indisponível — não escalar o erro só por causa de log.
        }
    }

    public function valuersmanagement($request)
    {
        $app = App::i();
        $this->pluginLog(
            "[INICIO] valuersmanagement - request: " . json_encode($request),
        );

        try {
            $file = $app->repo("File")->find($request["file"]);
            if (!$file) {
                $this->pluginLog(
                    "[ERRO] Arquivo não encontrado: ID " . $request["file"],
                );
                return [
                    "success" => false,
                    "message" => \MapasCulturais\i::__("Arquivo da planilha não encontrado."),
                ];
            }

            $this->pluginLog("[OK] Arquivo encontrado: " . $file->getPath());

            $spreadsheet = IOFactory::load($file->getPath());
            $this->pluginLog("[OK] Planilha carregada");

            $data = $this->getSpreadsheetData($spreadsheet);
            $this->pluginLog("[OK] Linhas extraídas: " . count($data));

            if (empty($data)) {
                $this->pluginLog("[WARN] Planilha vazia após leitura.");
                return [
                    "success" => false,
                    "message" => \MapasCulturais\i::__("A planilha não contém dados para importar."),
                ];
            }

            $committee = $request["committee"] ?? null;
            $mode = $this->normalizeImportMode($request["mode"] ?? null);
            $this->pluginLog("[OK] Modo de importação: " . $mode);

            $this->buildList($data, $file->owner, $committee, $mode);
            $this->pluginLog("[OK] buildList executado");

            // Move o arquivo para o histórico em vez de deletar, preservando
            // a planilha para auditoria/download posterior.
            $history = $this->archiveProcessedFile($file, [
                "committee" => $committee,
                "mode" => $mode,
                "rows_total" => count($data),
                "processed_at" => date("c"),
                "processed_by" => $app->user && $app->user->id ? (int) $app->user->id : null,
            ]);

            $this->pluginLog(
                "[OK] Arquivo arquivado no histórico. ID={$history->id}, group={$history->group}",
            );

            return [
                "success" => true,
                "message" => \MapasCulturais\i::__("Planilha processada com sucesso."),
                "history_file_id" => $history->id,
            ];
        } catch (\Throwable $e) {
            $this->pluginLog(
                "[ERRO] Exceção em valuersmanagement: " . get_class($e) . ": " . $e->getMessage(),
            );
            return [
                "success" => false,
                "message" => \MapasCulturais\i::__("Falha ao processar a planilha: ") . $e->getMessage(),
            ];
        } finally {
            $this->pluginLog("[FIM] valuersmanagement");
        }
    }

    /**
     * Move o arquivo processado do grupo "evalmaster" para "evalmaster-history",
     * gravando metadados do processamento na propriedade `description` (JSON).
     * Faz merge com metadados pré-existentes (ex.: `committee` gravado no
     * upload), garantindo que a planilha continue corretamente associada à
     * comissão correspondente.
     */
    protected function archiveProcessedFile(\MapasCulturais\Entities\File $file, array $metadata): \MapasCulturais\Entities\File
    {
        $existing = [];
        if (is_string($file->description) && $file->description !== '') {
            $decoded = json_decode($file->description, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }
        $merged = array_merge($existing, $metadata);

        $file->group = self::FILE_GROUP_HISTORY;
        $file->description = json_encode($merged, JSON_UNESCAPED_UNICODE);
        $file->save(true);
        return $file;
    }

    public function buildList($values, Opportunity $opportunity, $committee, $mode = self::IMPORT_MODE_COMPLEMENT)
    {
        $app = App::i();
        $mode = $this->normalizeImportMode($mode ?? self::IMPORT_MODE_COMPLEMENT);
        $this->pluginLog(
            "[buildList] Iniciado com " .
                count($values) .
                " linhas. Comitê: {$committee}. Modo: {$mode}.",
        );

        // Agrupa os avaliadores por número de inscrição, como no plugin original.
        // Normaliza a chave para minúsculas para tolerar variações de caixa entre
        // planilha e banco (ex.: "on-123" vs "ON-123"), já que em diferentes
        // ambientes o `Registration.number` pode estar gravado em qualquer caso.
        $groupedData = [];
        foreach ($values as $item) {
            $number = $this->getNumber($item);
            if ($number) {
                $key = $this->normalizeRegistrationNumber($number);
                $groupedData[$key] = $groupedData[$key] ?? [];
                $agentId = $this->getAgent($item);
                if ($agentId) {
                    $groupedData[$key][] = $agentId;
                }
            }
        }

        if ($mode === self::IMPORT_MODE_REPLACE) {
            $this->pluginLog(
                "[buildList][REPLACE] Limpando distribuições pendentes da oportunidade {$opportunity->id} para a comissão {$committee}, apenas nas inscrições da planilha.",
            );
            $this->resetCommitteeForOpportunity($opportunity, $committee, array_keys($groupedData));
        }

        $allValuerUserIds = [];
        $conn = $app->em->getConnection();

        foreach ($groupedData as $number => $agentIds) {
            try {
                $this->pluginLog("[buildList] Processando inscrição $number.");

                $registration = $this->findRegistrationByNumber($opportunity, $number);

                if (!$registration) {
                    $this->pluginLog(
                        "[buildList][WARN] Inscrição $number não encontrada.",
                    );
                    continue;
                }

                // Obtém os user_id dos agent_id fornecidos
                $ids = implode(", ", array_unique($agentIds));
                $users = $conn->fetchFirstColumn(
                    "SELECT user_id FROM agent WHERE id IN ($ids)",
                );

                if (empty($users)) {
                    $this->pluginLog(
                        "[buildList][WARN] Nenhum usuário encontrado para os agentes na inscrição $number.",
                    );
                    continue;
                }

                $related_agents = $registration->opportunity->evaluationMethodConfiguration->relatedAgents;

                $filter_users = [];
                foreach ($users as $user_id) {
                    $user = $app->repo("User")->find($user_id);

                    if (!empty($related_agents[$committee])) {
                        foreach($related_agents[$committee] as $relation) {
                            if($relation->id == $user->profile->id) {
                               $filter_users[] = $user->id;
                            }
                        }
                    }
                }

                if (empty($filter_users)) {
                    $this->pluginLog(
                        "[buildList][WARN] Nenhum usuário relacionado ao comitê $committee encontrado na inscrição $number.",
                    );
                    continue;
                }

                $filter_users = $this->normalizeUserIds($filter_users);

                $valuers = (array) ($registration->valuers ?: []);
                $current_exceptions = $registration->getValuersExceptionsList();

                $current_include_list = $this->normalizeUserIds(
                    (array) ($current_exceptions->include ?? []),
                );

                $current_exclude_list = $this->normalizeUserIds(
                    (array) ($current_exceptions->exclude ?? []),
                );

                foreach ($filter_users as $user_id) {
                    $valuers[$user_id] = $committee;
                }

                $include_list = $this->normalizeUserIds(
                    array_merge($current_include_list, $filter_users),
                );
                $exclude_list = $this->normalizeUserIds(
                    array_diff($current_exclude_list, $filter_users),
                );

                $valuers_exceptions_list = [
                    "exclude" => $exclude_list,
                    "include" => $include_list,
                ];

                $conn->update(
                    'registration',
                    ['valuers_exceptions_list' => json_encode($valuers_exceptions_list)],
                    ['id' => $registration->id]
                );

                if($valuers) {
                    $conn->update('registration', ['valuers' => json_encode($valuers)], ['id' => $registration->id]);
                }

                $app->em->flush();

                $this->pluginLog(
                    "[buildList] Avaliadores definidos para inscrição $number: " .
                        implode(", ", $filter_users),
                );

                // Coleta todos os user_id para a atualização de cache final
                $allValuerUserIds = array_merge($allValuerUserIds, $filter_users);
            } catch (\Throwable $e) {
                $this->pluginLog(
                    "[buildList][ERRO] Exceção na inscrição $number: " .
                        $e->getMessage(),
                );
            }
        }

        // Atualiza o cache dos avaliadores para as oportunidades
        $allValuerUserIds = array_unique($allValuerUserIds);
        $usersToUpdate = $app
            ->repo("User")
            ->findBy(["id" => $allValuerUserIds]);
        $opportunity->enqueueToPCacheRecreation($usersToUpdate);

        /** @var EvaluationMethodConfigurationAgentRelation[] */ 
        $relations = $opportunity->evaluationMethodConfiguration->getAgentRelations();
        foreach ($relations as $relation) {
            $relation->updateSummary();
        }

        $this->pluginLog("[buildList] Finalizado.");
    }

    protected function normalizeImportMode($mode): string
    {
        return $mode === self::IMPORT_MODE_REPLACE
            ? self::IMPORT_MODE_REPLACE
            : self::IMPORT_MODE_COMPLEMENT;
    }

    protected function normalizeUserIds(array $user_ids): array
    {
        return array_values(array_unique(array_map("intval", $user_ids)));
    }

    protected function getCommitteeValuerUserIds(array $valuers, $committee): array
    {
        $committee_valuers = [];

        foreach ($valuers as $user_id => $valuer_committee) {
            if ((string) $valuer_committee === (string) $committee) {
                $committee_valuers[] = (int) $user_id;
            }
        }

        return $this->normalizeUserIds($committee_valuers);
    }

    protected function resetCommitteeForOpportunity(Opportunity $opportunity, $committee, array $registration_numbers): void
    {
        $app = App::i();
        $conn = $app->em->getConnection();

        if (empty($registration_numbers)) {
            $this->pluginLog(
                "[resetCommitteeForOpportunity][WARN] Nenhuma inscrição encontrada na planilha. Nada será limpo.",
            );
            return;
        }

        foreach ($registration_numbers as $number) {
            $registration = $this->findRegistrationByNumber($opportunity, $number);

            if (!$registration) {
                $this->pluginLog(
                    "[resetCommitteeForOpportunity][WARN] Inscrição $number não encontrada. Nada será limpo para ela.",
                );
                continue;
            }

            $valuers = (array) ($registration->valuers ?: []);

            $committee_user_ids = $this->getCommitteeValuerUserIds($valuers, $committee);

            if (empty($committee_user_ids)) {
                continue;
            }

            $active_evaluation_user_ids = $this->getActiveEvaluationUserIds($registration->id, $committee_user_ids);

            if (!empty($active_evaluation_user_ids)) {
                $this->pluginLog(
                    "[resetCommitteeForOpportunity][KEEP] Inscrição {$registration->number} possui avaliação iniciada/concluída/enviada na comissão {$committee} pelos usuários: " .
                        implode(", ", $active_evaluation_user_ids) .
                        ". O replace será tratado como complemento para esta inscrição.",
                );
                continue;
            }

            $conn->executeStatement(
                "DELETE FROM registration_evaluation WHERE registration_id = :registration_id AND committee = :committee AND status IS NULL",
                [
                    "registration_id" => $registration->id,
                    "committee" => $committee,
                ],
            );

            $changed_valuers = false;
            foreach ($valuers as $user_id => $valuer_committee) {
                if ((string) $valuer_committee === (string) $committee) {
                    unset($valuers[$user_id]);
                    $changed_valuers = true;
                }
            }

            $exceptions = $registration->getValuersExceptionsList();

            $current_include = $this->normalizeUserIds((array) ($exceptions->include ?? []));
            $current_exclude = $this->normalizeUserIds((array) ($exceptions->exclude ?? []));

            // remove só os user_ids da comissão alvo, preservando exceções de outras comissões
            $exceptions->include = array_values(array_diff($current_include, $committee_user_ids));
            $exceptions->exclude = array_values(array_diff($current_exclude, $committee_user_ids));

            $update_data = [];

            if ($changed_valuers) {
                $update_data["valuers"] = json_encode($valuers ?: (object) []);
            }

            if ($exceptions->include !== $current_include || $exceptions->exclude !== $current_exclude) {
                $update_data["valuers_exceptions_list"] = json_encode($exceptions);
            }

            if ($update_data) {
                $conn->update(
                    "registration",
                    $update_data,
                    ["id" => $registration->id],
                );
                // Alinha a entidade em memória com o UPDATE direto; caso contrário,
                // buildList() leria valuers desatualizados pelo mapa de identidade do Doctrine.
                $app->em->refresh($registration);
            }
        }
    }

    protected function getActiveEvaluationUserIds($registration_id, array $user_ids): array
    {
        if (empty($user_ids)) {
            return [];
        }

        $app = App::i();
        $conn = $app->em->getConnection();
        $user_ids = $this->normalizeUserIds($user_ids);
        $user_ids_sql = implode(", ", $user_ids);

        return $this->normalizeUserIds(
            $conn->fetchFirstColumn(
                "SELECT user_id FROM registration_evaluation WHERE registration_id = :registration_id AND user_id IN ($user_ids_sql) AND status IS NOT NULL",
                ["registration_id" => $registration_id],
            ),
        );
    }

    protected function getCommitteeFromAgent(Opportunity $opportunity, $agentId)
    {
        $app = App::i();

        $emc = $app
            ->repo("EvaluationMethodConfiguration")
            ->findOneBy(["opportunity" => $opportunity]);

        if (!$emc) {
            $this->pluginLog(
                "[getCommitteeFromAgent] Configuração de avaliação não encontrada.",
            );
            return null;
        }

        $result = $app->em
            ->getConnection()
            ->fetchAssociative(
                "SELECT type FROM agent_relation WHERE object_type = 'MapasCulturais\\Entities\\EvaluationMethodConfiguration' AND object_id = :objectId AND agent_id = :agentId",
                [
                    "objectId" => $emc->id,
                    "agentId" => $agentId,
                ],
            );

        if ($result && isset($result["type"])) {
            return $result["type"];
        }

        return null;
    }

    function getNumber($item)
    {
        foreach ($item as $key => $value) {
            if (
                in_array(mb_strtolower($key), [
                    "inscrição",
                    "inscricao",
                    "number",
                    "número",
                ])
            ) {
                $value = $this->normalizeCellValue($value);
                return $value === null || $value === "" ? null : (string) $value;
            }
        }
        return null;
    }

    function getAgent($item)
    {
        foreach ($item as $key => $value) {
            if (
                in_array(mb_strtolower($key), [
                    "agente",
                    "id do agente",
                    "id do avaliador",
                ])
            ) {
                $value = $this->normalizeCellValue($value);
                if ($value === null || $value === "") {
                    return null;
                }
                return is_numeric($value) ? (int) $value : $value;
            }
        }
        return null;
    }

    /**
     * Normaliza o valor lido de uma célula. PhpSpreadsheet retorna instâncias de
     * RichText quando a célula referencia uma sharedString formada por múltiplos
     * runs (típico de planilhas que sofreram copy/paste ou que vêm com formatação
     * parcial, como anexos compartilhados via WhatsApp). Sem essa normalização,
     * usar o valor como chave de array dispara TypeError em PHP 8+.
     */
    protected function normalizeCellValue($value)
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        return $value;
    }

    /**
     * Normaliza um número de inscrição para comparação/agrupamento.
     * O prefixo da inscrição pode chegar em caixa diferente da gravada no banco
     * dependendo do ambiente (ex.: "on-123" vs "ON-123"); por isso, comparamos
     * em minúsculas tanto na chave do groupedData quanto na consulta SQL.
     */
    protected function normalizeRegistrationNumber($number): string
    {
        return mb_strtolower(trim((string) $number));
    }

    /**
     * Busca uma Registration pela oportunidade e pelo número, ignorando a caixa
     * do prefixo. Usa DQL com LOWER() nos dois lados para casar "on-XYZ" ou
     * "ON-XYZ" tanto na entrada quanto no banco.
     */
    protected function findRegistrationByNumber(Opportunity $opportunity, $number)
    {
        if ($number === null || $number === '') {
            return null;
        }

        $app = App::i();

        return $app->em
            ->createQuery(
                'SELECT r FROM MapasCulturais\\Entities\\Registration r ' .
                'WHERE r.opportunity = :opportunity ' .
                'AND LOWER(r.number) = LOWER(:number)'
            )
            ->setParameters([
                'opportunity' => $opportunity,
                'number' => (string) $number,
            ])
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    public function getSpreadsheetData($spreadsheet)
    {
        $worksheet = $spreadsheet->getActiveSheet();
        $header = [];
        $data = [];
        $firstRow = true;

        foreach ($worksheet->getRowIterator() as $row) {
            if ($firstRow) {
                $header = $this->getSpreadsheetHeader($row);
                $this->pluginLog(
                    "[getSpreadsheetData] Cabeçalho detectado: " .
                        json_encode($header),
                );
                $firstRow = false;
                continue;
            }

            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $rowData = [];
            $columnIndex = 0;
            foreach ($cellIterator as $cell) {
                $headerValue = $header[$columnIndex] ?? "col$columnIndex";
                $cellValue = $this->normalizeCellValue($cell->getValue());
                if ($cellValue !== null && $cellValue !== "") {
                    $rowData[$headerValue] = $cellValue;
                }
                $columnIndex++;
            }

            if ($rowData) {
                $data[] = $rowData;
            }
        }

        return $data;
    }

    public function getSpreadsheetHeader($row)
    {
        $header = [];
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);

        foreach ($cellIterator as $cell) {
            $value = $this->normalizeCellValue($cell->getValue());
            $header[] = $value === null ? "" : (string) $value;
        }

        return $header;
    }

    public function register()
    {
        $app = App::i();

        $allowed_mime_types = [
            '^text/csv$',
            '^application/vnd.ms-excel$',
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        ];
        $error_message = \MapasCulturais\i::__("O arquivo enviado não é válido.");

        // Grupo das planilhas pendentes — aceita múltiplos arquivos (uma por
        // comissão). O isolamento por comissão é feito via a property
        // `description` do arquivo, que carrega um JSON com `committee`.
        $pending = new \MapasCulturais\Definitions\FileGroup(
            self::FILE_GROUP_PENDING,
            $allowed_mime_types,
            $error_message,
            false,
            null,
            true,
        );
        $app->registerFileGroup("opportunity", $pending);

        // Grupo do histórico — múltiplos arquivos, preservados para auditoria
        // e download das planilhas já processadas.
        $history = new \MapasCulturais\Definitions\FileGroup(
            self::FILE_GROUP_HISTORY,
            $allowed_mime_types,
            $error_message,
            false,
            null,
            true,
        );
        $app->registerFileGroup("opportunity", $history);
    }
}
