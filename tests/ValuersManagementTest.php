<?php

namespace Tests;

use MapasCulturais\App;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\ConcurrentEndingAfter;
use Tests\Builders\PhasePeriods\Open;
use Tests\Enums\EvaluationMethods;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\UserDirector;
use ValuersManagement\Plugin;

class ValuersManagementTest extends TestCase
{
    use OpportunityBuilder,
        RegistrationDirector,
        UserDirector;

    private function getPlugin(): Plugin
    {
        $app = App::i();
        
        return $app->plugins['ValuersManagement'];
    }


    private function createScenario(): array
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);

        $committee = 'committee 1';

        $evaluation_phase_builder = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->addEvaluationPhase(EvaluationMethods::simple)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->setCommitteeValuersPerRegistration($committee, 2)
                ->save()
                ->addValuers(3, $committee)
                ->done();

        $opportunity = $evaluation_phase_builder->getInstance();

        $registrations = $this->registrationDirector->createSentRegistrations(
            $opportunity, 3
        );

        $opportunity->evaluationMethodConfiguration->redistributeCommitteeRegistrations();

        $relations = $opportunity->evaluationMethodConfiguration->getAgentRelationsGrouped()[$committee] ?? [];

        $valuer_agents = [];
        foreach ($relations as $relation) {
            $valuer_agents[] = $relation->agent;
        }

        return [
            'opportunity' => $opportunity,
            'registrations' => $registrations,
            'committee' => $committee,
            'valuer_agents' => $valuer_agents,
        ];
    }

    private function buildValuersImportData(array $registrations, array $valuer_agents): array
    {
        $valuers_import_rows = [];
        foreach ($registrations as $registration) {
            foreach ($valuer_agents as $valuer_agent) {
                $valuers_import_rows[] = [
                    'inscrição' => $registration->number,
                    'agente' => $valuer_agent->id,
                ];
            }
        }

        return $valuers_import_rows;
    }

    public function testComplementMode(): void
    {
        $app = App::i();
        $scenario = $this->createScenario();
        $plugin = $this->getPlugin();

        $opportunity = $scenario['opportunity'];
        $registrations = $scenario['registrations'];
        $committee = $scenario['committee'];
        $valuer_agents = $scenario['valuer_agents'];

        $registrations = array_map(
            fn($registration) => $registration->refreshed(),
            $registrations
        );

        $initial_valuers = [];
        foreach ($registrations as $registration) {
            $initial_valuers[$registration->id] = (array) ($registration->valuers ?: []);
        }

        $new_agent = $valuer_agents[2];
        $valuers_import_data = $this->buildValuersImportData($registrations, [$new_agent]);

        $app->disableAccessControl();
        $plugin->buildList($valuers_import_data, $opportunity, $committee, Plugin::IMPORT_MODE_COMPLEMENT);
        $app->enableAccessControl();
        $app->em->clear();

        foreach ($registrations as $registration) {
            $refreshed_registration = $app->repo('Registration')->find($registration->id);
            $valuers = (array) ($refreshed_registration->valuers ?: []);

            foreach ($initial_valuers[$registration->id] as $user_id => $current_committee) {
                $this->assertArrayHasKey(
                    (string) $user_id,
                    $valuers,
                    "Certificando que no modo complementar os avaliadores existentes permanecem na inscrição {$refreshed_registration->number}"
                );
            }

            $this->assertArrayHasKey(
                (string) $new_agent->user->id,
                $valuers,
                "Certificando que no modo complementar o novo avaliador está presente na inscrição {$refreshed_registration->number}"
            );

            $exceptions = $refreshed_registration->getValuersExceptionsList();
            $include_list = array_map('intval', (array) ($exceptions->include ?? []));

            $this->assertContains(
                $new_agent->user->id,
                $include_list,
                "Certificando que a lista de inclusão contém o novo avaliador na inscrição {$refreshed_registration->number}"
            );
        }
    }

    public function testReplaceMode(): void
    {
        $app = App::i();
        $scenario = $this->createScenario();
        $plugin = $this->getPlugin();

        $opportunity = $scenario['opportunity'];
        $registrations = $scenario['registrations'];
        $committee = $scenario['committee'];
        $valuer_agents = $scenario['valuer_agents'];

        $registrations = array_map(
            fn($registration) => $registration->refreshed(),
            $registrations
        );

        $initial_valuers = [];
        foreach ($registrations as $registration) {
            $initial_valuers[$registration->id] = (array) ($registration->valuers ?: []);
        }

        $replace_agent = $valuer_agents[0];
        $valuers_import_data = $this->buildValuersImportData($registrations, [$replace_agent]);

        $app->disableAccessControl();
        $plugin->buildList($valuers_import_data, $opportunity, $committee, Plugin::IMPORT_MODE_REPLACE);
        $app->enableAccessControl();
        $app->em->clear();

        foreach ($registrations as $registration) {
            $refreshed_registration = $app->repo('Registration')->find($registration->id);
            $valuers = (array) ($refreshed_registration->valuers ?: []);

            $this->assertArrayHasKey(
                (string) $replace_agent->user->id,
                $valuers,
                "Certificando que no modo substituir o avaliador da planilha está presente na inscrição {$refreshed_registration->number}"
            );

            foreach ($initial_valuers[$registration->id] as $user_id => $current_committee) {
                if ((int) $user_id !== $replace_agent->user->id) {
                    $this->assertArrayNotHasKey(
                        (string) $user_id,
                        $valuers,
                        "Certificando que no modo substituir os avaliadores antigos são removidos na inscrição {$refreshed_registration->number}"
                    );
                }
            }

            $exceptions = $refreshed_registration->getValuersExceptionsList();
            $include_list = array_map('intval', (array) ($exceptions->include ?? []));
            $exclude_list = array_map('intval', (array) ($exceptions->exclude ?? []));

            $this->assertContains(
                $replace_agent->user->id,
                $include_list,
                "Certificando que a lista de inclusão contém o avaliador da planilha na inscrição {$refreshed_registration->number}"
            );

            $this->assertEmpty(
                $exclude_list,
                "Certificando que a lista de exclusão está vazia após substituição na inscrição {$refreshed_registration->number}"
            );
        }
    }

    /**
     * Constrói cenário com várias comissões (committee_a, committee_b, committee_c).
     * Cada comissão tem seu pool de avaliadores e distribuição feita.
     */
    private function createMultiCommitteeScenario(): array
    {
        $admin = $this->userDirector->createUser('admin');
        $this->login($admin);

        $committees = ['committee_a', 'committee_b', 'committee_c'];

        $evaluation_phase_builder = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->addEvaluationPhase(EvaluationMethods::simple)
                ->setEvaluationPeriod(new ConcurrentEndingAfter)
                ->setCommitteeValuersPerRegistration('committee_a', 1)
                ->setCommitteeValuersPerRegistration('committee_b', 1)
                ->setCommitteeValuersPerRegistration('committee_c', 1)
                ->save()
                ->addValuers(2, 'committee_a')
                ->addValuers(2, 'committee_b')
                ->addValuers(2, 'committee_c')
                ->done();

        $opportunity = $evaluation_phase_builder->getInstance();

        $registrations = $this->registrationDirector->createSentRegistrations(
            $opportunity, 3
        );

        $opportunity->evaluationMethodConfiguration->redistributeCommitteeRegistrations();

        $valuer_agents_by_committee = [];
        foreach ($committees as $committee) {
            $relations = $opportunity->evaluationMethodConfiguration->getAgentRelationsGrouped()[$committee] ?? [];
            $valuer_agents_by_committee[$committee] = array_map(fn($r) => $r->agent, $relations);
        }

        return [
            'opportunity' => $opportunity,
            'registrations' => $registrations,
            'committees' => $committees,
            'valuer_agents_by_committee' => $valuer_agents_by_committee,
        ];
    }

    /**
     * Cenário: oportunidade com 3 comissões (A, B, C), distribuição já feita em todas.
     * Ao substituir SOMENTE committee_a, as comissões B e C devem permanecer intactas
     * (mesmos avaliadores, mesma associação user → comissão).
     */
    public function testReplaceModeOnlyAffectsTargetCommittee(): void
    {
        $app = App::i();
        $scenario = $this->createMultiCommitteeScenario();
        $plugin = $this->getPlugin();

        $opportunity = $scenario['opportunity'];
        $registrations = $scenario['registrations'];
        $valuer_agents_by_committee = $scenario['valuer_agents_by_committee'];

        $target_committee = 'committee_a';
        $preserved_committees = ['committee_b', 'committee_c'];

        // Snapshot do estado inicial: user_id => comissão por inscrição
        $initial_state = [];
        foreach ($registrations as $registration) {
            $refreshed = $registration->refreshed();
            $valuers = (array) ($refreshed->valuers ?: []);
            $by_committee = [];
            foreach ($valuers as $user_id => $valuer_committee) {
                $by_committee[$valuer_committee][] = (int) $user_id;
            }
            $initial_state[$registration->id] = [
                'valuers' => $valuers,
                'by_committee' => $by_committee,
            ];
        }

        // Avaliador a ser usado na substituição: o segundo do pool da committee_a
        $target_pool = $valuer_agents_by_committee[$target_committee];
        $new_agent = $target_pool[1] ?? $target_pool[0];

        $valuers_import_data = $this->buildValuersImportData($registrations, [$new_agent]);

        $app->disableAccessControl();
        $plugin->buildList($valuers_import_data, $opportunity, $target_committee, Plugin::IMPORT_MODE_REPLACE);
        $app->enableAccessControl();
        $app->em->clear();

        foreach ($registrations as $registration) {
            $refreshed = $app->repo('Registration')->find($registration->id);
            $current_valuers = (array) ($refreshed->valuers ?: []);

            // Novo avaliador presente e associado à comissão alvo
            $this->assertArrayHasKey(
                (string) $new_agent->user->id,
                $current_valuers,
                "Novo avaliador da planilha deve estar presente em {$refreshed->number}"
            );
            $this->assertEquals(
                $target_committee,
                $current_valuers[(string) $new_agent->user->id],
                "Novo avaliador deve estar associado a {$target_committee} em {$refreshed->number}"
            );

            // Avaliadores antigos da comissão alvo (diferentes do novo) removidos
            $initial_target_users = $initial_state[$registration->id]['by_committee'][$target_committee] ?? [];
            foreach ($initial_target_users as $user_id) {
                if ($user_id === $new_agent->user->id) {
                    continue;
                }
                $this->assertArrayNotHasKey(
                    (string) $user_id,
                    $current_valuers,
                    "Avaliador antigo {$user_id} de {$target_committee} deve ser removido em {$refreshed->number}"
                );
            }

            // Comissões preservadas: cada user_id que existia continua presente e na mesma comissão
            foreach ($preserved_committees as $preserved) {
                $initial_users = $initial_state[$registration->id]['by_committee'][$preserved] ?? [];
                foreach ($initial_users as $user_id) {
                    $this->assertArrayHasKey(
                        (string) $user_id,
                        $current_valuers,
                        "Avaliador {$user_id} de {$preserved} deve permanecer em {$refreshed->number}"
                    );
                    $this->assertEquals(
                        $preserved,
                        $current_valuers[(string) $user_id],
                        "Avaliador {$user_id} deve continuar associado a {$preserved} em {$refreshed->number}"
                    );
                }
            }
        }
    }

    /**
     * Cenário: include/exclude já contêm user_ids de OUTRAS comissões antes da importação.
     * Ao substituir committee_a, as entradas das outras comissões em include/exclude
     * devem permanecer; apenas user_ids da committee_a são afetados.
     */
    public function testReplaceModePreservesExceptionsFromOtherCommittees(): void
    {
        $app = App::i();
        $scenario = $this->createMultiCommitteeScenario();
        $plugin = $this->getPlugin();

        $opportunity = $scenario['opportunity'];
        $registrations = $scenario['registrations'];
        $valuer_agents_by_committee = $scenario['valuer_agents_by_committee'];

        $target_committee = 'committee_a';

        $other_user_id_b = $valuer_agents_by_committee['committee_b'][0]->user->id;
        $other_user_id_c = $valuer_agents_by_committee['committee_c'][0]->user->id;

        // Injeta entradas em include/exclude pertencentes a outras comissões (cenário manual)
        $conn = $app->em->getConnection();
        $app->disableAccessControl();
        foreach ($registrations as $registration) {
            $refreshed = $app->repo('Registration')->find($registration->id);
            $exceptions = $refreshed->getValuersExceptionsList();

            $include = array_map('intval', (array) ($exceptions->include ?? []));
            $exclude = array_map('intval', (array) ($exceptions->exclude ?? []));

            $include[] = $other_user_id_b;
            $exclude[] = $other_user_id_c;

            $exceptions->include = array_values(array_unique($include));
            $exceptions->exclude = array_values(array_unique($exclude));

            $conn->update(
                'registration',
                ['valuers_exceptions_list' => json_encode($exceptions)],
                ['id' => $refreshed->id]
            );
        }
        $app->em->clear();

        $target_pool = $valuer_agents_by_committee[$target_committee];
        $new_agent = $target_pool[1] ?? $target_pool[0];
        $valuers_import_data = $this->buildValuersImportData($registrations, [$new_agent]);

        $plugin->buildList($valuers_import_data, $opportunity, $target_committee, Plugin::IMPORT_MODE_REPLACE);
        $app->enableAccessControl();
        $app->em->clear();

        foreach ($registrations as $registration) {
            $refreshed = $app->repo('Registration')->find($registration->id);
            $exceptions = $refreshed->getValuersExceptionsList();

            $include_list = array_map('intval', (array) ($exceptions->include ?? []));
            $exclude_list = array_map('intval', (array) ($exceptions->exclude ?? []));

            $this->assertContains(
                $other_user_id_b,
                $include_list,
                "Include de committee_b deve permanecer após substituir {$target_committee} em {$refreshed->number}"
            );

            $this->assertContains(
                $other_user_id_c,
                $exclude_list,
                "Exclude de committee_c deve permanecer após substituir {$target_committee} em {$refreshed->number}"
            );

            // O novo avaliador da committee_a foi incluído
            $this->assertContains(
                $new_agent->user->id,
                $include_list,
                "Include deve conter o novo avaliador da {$target_committee} em {$refreshed->number}"
            );
        }
    }

    /**
     * Cobre a leitura de planilhas que vêm com células em rich text (típico
     * quando o arquivo é compartilhado via WhatsApp ou tem trechos copiados/colados
     * de outra fonte). O PhpSpreadsheet retorna instâncias de RichText e o plugin
     * precisa convertê-las em strings simples na hora de extrair os dados.
     */
    public function testGetSpreadsheetDataNormalizesRichTextValues(): void
    {
        $plugin = $this->getPlugin();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'INSCRICAO');
        $sheet->setCellValue('B1', 'AGENTE');

        // Linha 2: célula INSCRICAO em rich text dividida em dois runs, como
        // o LibreOffice grava quando há formatação parcial na mesma célula.
        $rich = new RichText();
        $rich->createText('on-');
        $rich->createTextRun('1300056125');
        $sheet->setCellValue('A2', $rich);
        $sheet->setCellValue('B2', 13367);

        // Linha 3: também rich text, valor com espaços em branco para garantir o trim.
        $rich2 = new RichText();
        $rich2->createText(' on-');
        $rich2->createTextRun('999 ');
        $sheet->setCellValue('A3', $rich2);
        $sheet->setCellValue('B3', 12345);

        // Linha 4: controle, em string simples.
        $sheet->setCellValue('A4', 'on-555');
        $sheet->setCellValue('B4', 11111);

        $data = $plugin->getSpreadsheetData($spreadsheet);

        $this->assertCount(3, $data, 'Deve extrair as 3 linhas de dados');

        foreach ($data as $index => $row) {
            $this->assertIsString(
                $row['INSCRICAO'],
                "A coluna INSCRICAO da linha {$index} deve ter sido normalizada para string"
            );
        }

        $this->assertSame('on-1300056125', $data[0]['INSCRICAO']);
        $this->assertSame(13367, $data[0]['AGENTE']);

        $this->assertSame('on-999', $data[1]['INSCRICAO']);
        $this->assertSame(12345, $data[1]['AGENTE']);

        $this->assertSame('on-555', $data[2]['INSCRICAO']);
        $this->assertSame(11111, $data[2]['AGENTE']);
    }

    /**
     * Cenário completo do buildList recebendo números de inscrição como objetos
     * RichText (mesmo formato que sai do PhpSpreadsheet ao ler uma planilha
     * vinda do WhatsApp). Antes da correção isso disparava TypeError ao usar o
     * valor como chave de array; depois deve processar normalmente.
     */
    public function testBuildListAcceptsRichTextRegistrationNumbers(): void
    {
        $app = App::i();
        $scenario = $this->createScenario();
        $plugin = $this->getPlugin();

        $opportunity = $scenario['opportunity'];
        $registrations = $scenario['registrations'];
        $committee = $scenario['committee'];
        $valuer_agents = $scenario['valuer_agents'];

        $new_agent = $valuer_agents[2];

        $valuers_import_data = [];
        foreach ($registrations as $registration) {
            // Quebra "on-XYZ" em dois runs: o prefixo "on-" e o número.
            $prefix = 'on-';
            $suffix = (string) substr((string) $registration->number, strlen($prefix));

            $rich = new RichText();
            $rich->createText($prefix);
            $rich->createTextRun($suffix);

            $valuers_import_data[] = [
                'inscrição' => $rich,
                'agente' => $new_agent->id,
            ];
        }

        $app->disableAccessControl();
        $plugin->buildList($valuers_import_data, $opportunity, $committee, Plugin::IMPORT_MODE_COMPLEMENT);
        $app->enableAccessControl();
        $app->em->clear();

        foreach ($registrations as $registration) {
            $refreshed = $app->repo('Registration')->find($registration->id);
            $valuers = (array) ($refreshed->valuers ?: []);

            $this->assertArrayHasKey(
                (string) $new_agent->user->id,
                $valuers,
                "Mesmo com INSCRICAO em RichText, o avaliador deve ser associado à inscrição {$refreshed->number}"
            );

            $exceptions = $refreshed->getValuersExceptionsList();
            $include_list = array_map('intval', (array) ($exceptions->include ?? []));

            $this->assertContains(
                $new_agent->user->id,
                $include_list,
                "Include deve conter o novo avaliador na inscrição {$refreshed->number}"
            );
        }
    }

    /**
     * Garante que a importação consegue casar a inscrição independentemente da
     * caixa do prefixo "on-". Em diferentes ambientes o `Registration.number`
     * pode estar gravado em qualquer caso, e a planilha pode chegar com o
     * prefixo em qualquer caso também.
     */
    public function testBuildListMatchesRegistrationCaseInsensitively(): void
    {
        $app = App::i();
        $scenario = $this->createScenario();
        $plugin = $this->getPlugin();

        $opportunity = $scenario['opportunity'];
        $registrations = $scenario['registrations'];
        $committee = $scenario['committee'];
        $valuer_agents = $scenario['valuer_agents'];

        $new_agent = $valuer_agents[2];

        $valuers_import_data = [];
        foreach ($registrations as $registration) {
            $upper_number = strtoupper((string) $registration->number);
            $this->assertNotSame(
                $registration->number,
                $upper_number,
                'Pré-condição: o número da inscrição deve ter prefixo em minúsculas para o teste fazer sentido'
            );
            $valuers_import_data[] = [
                'inscrição' => $upper_number,
                'agente' => $new_agent->id,
            ];
        }

        $app->disableAccessControl();
        $plugin->buildList($valuers_import_data, $opportunity, $committee, Plugin::IMPORT_MODE_COMPLEMENT);
        $app->enableAccessControl();
        $app->em->clear();

        foreach ($registrations as $registration) {
            $refreshed = $app->repo('Registration')->find($registration->id);
            $valuers = (array) ($refreshed->valuers ?: []);

            $this->assertArrayHasKey(
                (string) $new_agent->user->id,
                $valuers,
                "A inscrição {$refreshed->number} deve ser encontrada mesmo quando o prefixo na planilha está em outro caso"
            );
        }
    }

    /**
     * Gera uma planilha temporária em memória com a estrutura esperada pelo
     * plugin (cabeçalhos INSCRICAO/AGENTE) a partir das registrations e agentes
     * informados. Devolve o caminho do arquivo gerado.
     */
    private function writeTempSpreadsheet(array $registrations, array $valuer_agents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'valuers-test-') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'INSCRICAO');
        $sheet->setCellValue('B1', 'AGENTE');

        $row = 2;
        foreach ($registrations as $registration) {
            foreach ($valuer_agents as $valuer_agent) {
                $sheet->setCellValue("A{$row}", $registration->number);
                $sheet->setCellValue("B{$row}", $valuer_agent->id);
                $row++;
            }
        }

        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        return $path;
    }

    /**
     * Constrói um cenário e cria uma entidade File real no grupo "evalmaster"
     * (planilha pendente), apontando para um arquivo gerado em disco. Retorna
     * o array do cenário acrescido das chaves `file` e `file_path`.
     */
    private function createScenarioWithPendingFile(array $valuer_agents_subset = null): array
    {
        $app = App::i();
        $scenario = $this->createScenario();

        $registrations = array_map(
            fn($r) => $r->refreshed(),
            $scenario['registrations']
        );
        $scenario['registrations'] = $registrations;

        $agents = $valuer_agents_subset ?? [$scenario['valuer_agents'][0]];
        $path = $this->writeTempSpreadsheet($registrations, $agents);

        $app->disableAccessControl();
        $opportunity = $app->repo('Opportunity')->find($scenario['opportunity']->id);
        $file = new \MapasCulturais\Entities\OpportunityFile([
            'name' => basename($path),
            'tmp_name' => $path,
            'error' => 0,
            'size' => filesize($path),
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
        $file->group = Plugin::FILE_GROUP_PENDING;
        $file->owner = $opportunity;
        $file->description = json_encode(['committee' => $scenario['committee']]);
        $file->save(true);
        $app->em->flush();
        $app->enableAccessControl();

        $scenario['file'] = $file;
        $scenario['file_path'] = $path;
        return $scenario;
    }

    /**
     * Em caso de sucesso, o arquivo processado NÃO deve ser deletado: deve ser
     * movido para o grupo "evalmaster-history" e ter o `description` populado
     * com metadados (comissão, modo, contagem de linhas, timestamp).
     */
    public function testValuersmanagementMovesFileToHistoryOnSuccess(): void
    {
        $app = App::i();
        $plugin = $this->getPlugin();

        $scenario = $this->createScenarioWithPendingFile();
        $file_id = $scenario['file']->id;

        $app->disableAccessControl();
        $result = $plugin->valuersmanagement([
            'file' => $file_id,
            'committee' => $scenario['committee'],
            'mode' => Plugin::IMPORT_MODE_COMPLEMENT,
        ]);
        $app->enableAccessControl();
        $app->em->clear();

        $this->assertIsArray($result);
        $this->assertTrue($result['success'] ?? false, 'O processamento deve indicar sucesso.');

        $reloaded = $app->repo('File')->find($file_id);
        $this->assertNotNull($reloaded, 'O arquivo NÃO deve ter sido deletado.');
        $this->assertSame(
            Plugin::FILE_GROUP_HISTORY,
            $reloaded->group,
            'O arquivo deve ter sido movido para o grupo de histórico.'
        );

        $meta = json_decode($reloaded->description ?? '', true);
        $this->assertIsArray($meta, 'A description do arquivo deve conter um JSON de metadados.');
        $this->assertSame($scenario['committee'], $meta['committee'] ?? null);
        $this->assertSame(Plugin::IMPORT_MODE_COMPLEMENT, $meta['mode'] ?? null);
        $this->assertGreaterThan(0, $meta['rows_total'] ?? 0, 'rows_total deve estar populado.');
        $this->assertNotEmpty($meta['processed_at'] ?? null);
    }

    /**
     * Garante que o grupo "evalmaster" aceita múltiplos arquivos (um por
     * comissão). Subir uma planilha pendente para a comissão A não deve
     * remover a planilha pendente da comissão B — cada uma é arquivada
     * independentemente quando processada.
     */
    public function testEvalmasterGroupAcceptsMultiplePendingFilesPerCommittee(): void
    {
        $app = App::i();

        $pending_group = $app->getRegisteredFileGroup('opportunity', Plugin::FILE_GROUP_PENDING);
        $this->assertNotNull($pending_group, 'O grupo evalmaster deve estar registrado.');
        $this->assertFalse(
            $pending_group->unique,
            'O grupo evalmaster precisa aceitar múltiplos arquivos (um por comissão).'
        );

        $scenario_a = $this->createScenarioWithPendingFile();
        $opportunity_id = $scenario_a['opportunity']->id;
        $committee_a = $scenario_a['committee'];

        // Cria um segundo arquivo pendente no MESMO opportunity, mas com
        // committee diferente — simulando o cenário do bug relatado.
        $registrations = array_map(
            fn($r) => $r->refreshed(),
            $scenario_a['registrations']
        );
        $other_path = $this->writeTempSpreadsheet($registrations, [$scenario_a['valuer_agents'][0]]);
        $committee_b = $committee_a . '-second';

        $app->disableAccessControl();
        $opportunity = $app->repo('Opportunity')->find($opportunity_id);
        $file_b = new \MapasCulturais\Entities\OpportunityFile([
            'name' => basename($other_path),
            'tmp_name' => $other_path,
            'error' => 0,
            'size' => filesize($other_path),
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
        $file_b->group = Plugin::FILE_GROUP_PENDING;
        $file_b->owner = $opportunity;
        $file_b->description = json_encode(['committee' => $committee_b]);
        $file_b->save(true);
        $app->em->flush();
        $app->enableAccessControl();

        $app->em->clear();
        $opportunity_reloaded = $app->repo('Opportunity')->find($opportunity_id);
        $pending = $app->repo('OpportunityFile')->findBy([
            'group' => Plugin::FILE_GROUP_PENDING,
            'owner' => $opportunity_reloaded,
        ]);
        $this->assertCount(2, $pending, 'Ambas as planilhas pendentes devem coexistir.');

        $committees = array_map(function ($f) {
            $meta = json_decode($f->description ?? '', true);
            return $meta['committee'] ?? null;
        }, $pending);
        sort($committees);
        $expected = [$committee_a, $committee_b];
        sort($expected);
        $this->assertSame($expected, $committees, 'Cada arquivo deve estar vinculado à sua própria comissão.');
    }

    /**
     * Quando o id de arquivo informado não existe, o método deve retornar
     * sucesso=false (sem disparar exceção) e nenhuma planilha deve ter sido
     * movida para o histórico.
     */
    public function testValuersmanagementReturnsErrorWhenFileNotFound(): void
    {
        $app = App::i();
        $plugin = $this->getPlugin();

        $scenario = $this->createScenario();

        $app->disableAccessControl();
        $result = $plugin->valuersmanagement([
            'file' => 999999999, // id inexistente
            'committee' => $scenario['committee'],
            'mode' => Plugin::IMPORT_MODE_COMPLEMENT,
        ]);
        $app->enableAccessControl();

        $this->assertIsArray($result);
        $this->assertFalse($result['success'] ?? true, 'success deve ser false para arquivo inexistente.');
        $this->assertNotEmpty($result['message'] ?? null, 'Deve retornar uma mensagem de erro.');
    }

    /**
     * Quando a planilha contém apenas o cabeçalho (sem linhas de dados), o
     * processamento deve falhar com mensagem explícita e o arquivo deve
     * permanecer no grupo "evalmaster" para permitir reprocesso/descarte
     * manual pelo usuário.
     */
    public function testValuersmanagementKeepsFileInPendingWhenSpreadsheetIsEmpty(): void
    {
        $app = App::i();
        $plugin = $this->getPlugin();

        $scenario = $this->createScenario();

        // Gera uma planilha vazia (só com cabeçalho)
        $path = tempnam(sys_get_temp_dir(), 'valuers-empty-') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'INSCRICAO');
        $sheet->setCellValue('B1', 'AGENTE');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        $app->disableAccessControl();
        $opportunity = $app->repo('Opportunity')->find($scenario['opportunity']->id);
        $file = new \MapasCulturais\Entities\OpportunityFile([
            'name' => basename($path),
            'tmp_name' => $path,
            'error' => 0,
            'size' => filesize($path),
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
        $file->group = Plugin::FILE_GROUP_PENDING;
        $file->owner = $opportunity;
        $file->save(true);
        $app->em->flush();
        $file_id = $file->id;

        $result = $plugin->valuersmanagement([
            'file' => $file_id,
            'committee' => $scenario['committee'],
            'mode' => Plugin::IMPORT_MODE_COMPLEMENT,
        ]);
        $app->enableAccessControl();
        $app->em->clear();

        $this->assertIsArray($result);
        $this->assertFalse($result['success'] ?? true, 'Planilha vazia deve falhar.');
        $this->assertNotEmpty($result['message'] ?? null);

        $reloaded = $app->repo('File')->find($file_id);
        $this->assertNotNull($reloaded, 'O arquivo não deve ter sido removido.');
        $this->assertSame(
            Plugin::FILE_GROUP_PENDING,
            $reloaded->group,
            'Em falha, o arquivo deve permanecer no grupo pendente.'
        );
    }

    /**
     * Quando a planilha tem a mesma inscrição duplicada com cases diferentes
     * (ex.: "on-123" e "ON-123") e cada linha aponta para um agente diferente,
     * o agrupamento deve consolidar as duas linhas no mesmo número e associar
     * os dois agentes à inscrição correspondente.
     */
    public function testBuildListGroupsDuplicateNumbersWithDifferentCases(): void
    {
        $app = App::i();
        $scenario = $this->createScenario();
        $plugin = $this->getPlugin();

        $opportunity = $scenario['opportunity'];
        $registrations = $scenario['registrations'];
        $committee = $scenario['committee'];
        $valuer_agents = $scenario['valuer_agents'];

        $agent_a = $valuer_agents[0];
        $agent_b = $valuer_agents[1];

        $valuers_import_data = [];
        foreach ($registrations as $registration) {
            $valuers_import_data[] = [
                'inscrição' => strtolower((string) $registration->number),
                'agente' => $agent_a->id,
            ];
            $valuers_import_data[] = [
                'inscrição' => strtoupper((string) $registration->number),
                'agente' => $agent_b->id,
            ];
        }

        $app->disableAccessControl();
        $plugin->buildList($valuers_import_data, $opportunity, $committee, Plugin::IMPORT_MODE_COMPLEMENT);
        $app->enableAccessControl();
        $app->em->clear();

        foreach ($registrations as $registration) {
            $refreshed = $app->repo('Registration')->find($registration->id);
            $valuers = (array) ($refreshed->valuers ?: []);

            $this->assertArrayHasKey(
                (string) $agent_a->user->id,
                $valuers,
                "A linha com prefixo minúsculo deve associar o agente A à inscrição {$refreshed->number}"
            );

            $this->assertArrayHasKey(
                (string) $agent_b->user->id,
                $valuers,
                "A linha com prefixo maiúsculo deve associar o agente B à mesma inscrição {$refreshed->number}"
            );
        }
    }
}
