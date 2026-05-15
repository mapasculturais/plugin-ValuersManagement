<?php

namespace ValuersManagement;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Plugin extends \MapasCulturais\Plugin
{
    public const IMPORT_MODE_COMPLEMENT = "complement";
    public const IMPORT_MODE_REPLACE = "replace";

    public function _init()
    {
        $app = App::i();

        $app->view->enqueueStyle(
            "app-v2",
            "ValuersManagement-v2",
            "css/plugin-ValuersManagement.css",
        );

        $self = $this;

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

            if ($self->valuersmanagement($request)) {
                $this->json(true);
            }
        });
    }

    protected function pluginLog(string $message)
    {
        $logDir = __DIR__ . "/logs";
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $logFile = $logDir . "/valuersmanagement.log";
        $date = date("Y-m-d H:i:s");
        $formattedMessage = "[$date] $message" . PHP_EOL;

        file_put_contents($logFile, $formattedMessage, FILE_APPEND);
    }

    public function valuersmanagement($request)
    {
        $app = App::i();
        $this->pluginLog(
            "[INICIO] valuersmanagement - request: " . json_encode($request),
        );

        $file = $app->repo("File")->find($request["file"]);
        if (!$file) {
            $this->pluginLog(
                "[ERRO] Arquivo não encontrado: ID " . $request["file"],
            );
            return true;
        }

        $this->pluginLog("[OK] Arquivo encontrado: " . $file->getPath());

        $spreadsheet = IOFactory::load($file->getPath());
        $this->pluginLog("[OK] Planilha carregada");

        $data = $this->getSpreadsheetData($spreadsheet);
        $this->pluginLog("[OK] Linhas extraídas: " . count($data));

        if (empty($data)) {
            $this->pluginLog("[WARN] Planilha vazia após leitura.");
        } else {
            $committee = $request["committee"] ?? null;
            $mode = $this->normalizeImportMode($request["mode"] ?? null);
            $this->pluginLog("[OK] Modo de importação: " . $mode);
            $this->buildList($data, $file->owner, $committee, $mode);
            $this->pluginLog("[OK] buildList executado");

            // Deleta o arquivo após o uso, como no plugin original
            $app->repo("File")->find($request["file"])->delete(true);
            $this->pluginLog("[OK] Arquivo deletado.");
        }

        $this->pluginLog("[FIM] valuersmanagement");
        return true;
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

        if ($mode === self::IMPORT_MODE_REPLACE) {
            $this->pluginLog(
                "[buildList][REPLACE] Limpando distribuições pendentes da oportunidade {$opportunity->id} para a comissão {$committee}.",
            );
            $this->resetCommitteeForOpportunity($opportunity, $committee);
        }

        // Agrupa os avaliadores por número de inscrição, como no plugin original
        $groupedData = [];
        foreach ($values as $item) {
            $number = $this->getNumber($item);
            if ($number) {
                $groupedData[$number] = $groupedData[$number] ?? [];
                $agentId = $this->getAgent($item);
                if ($agentId) {
                    $groupedData[$number][] = $agentId;
                }
            }
        }

        $allValuerUserIds = [];
        $conn = $app->em->getConnection();

        foreach ($groupedData as $number => $agentIds) {
            try {
                $this->pluginLog("[buildList] Processando inscrição $number.");

                $registration = $app->repo("Registration")->findOneBy([
                    "opportunity" => $opportunity,
                    "number" => $number,
                ]);

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

    protected function resetCommitteeForOpportunity(Opportunity $opportunity, $committee): void
    {
        $app = App::i();
        $conn = $app->em->getConnection();

        // Remove avaliações pendentes da comissão para todas as inscrições da oportunidade
        $registrations_ids = $conn->fetchFirstColumn(
            "SELECT id FROM registration WHERE opportunity_id = :opportunity_id",
            ["opportunity_id" => $opportunity->id],
        );

        foreach ($registrations_ids as $registration_id) {
            $conn->delete("registration_evaluation", [
                "registration_id" => $registration_id,
                "committee" => $committee,
                "status" => 0,
            ]);
        }

        $registrations = $app->repo("Registration")->findBy(["opportunity" => $opportunity]);

        foreach ($registrations as $registration) {
            $valuers = (array) ($registration->valuers ?: []);

            // user_ids que pertencem à comissão alvo (coletados antes do unset)
            $committee_user_ids = $this->getCommitteeValuerUserIds($valuers, $committee);

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
                return $value;
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
                return $value;
            }
        }
        return null;
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
                $cellValue = $cell->getValue();
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
            $header[] = $cell->getValue();
        }

        return $header;
    }

    public function register()
    {
        $app = App::i();
        $file_group_definition = new \MapasCulturais\Definitions\FileGroup(
            "evalmaster",
            [
                '^text/csv$',
                '^application/vnd.ms-excel$',
                "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            ],
            "O arquivo enviado não é válido.",
            true,
            null,
            true,
        );
        $app->registerFileGroup("opportunity", $file_group_definition);
    }
}
