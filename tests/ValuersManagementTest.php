<?php

namespace Tests;

use MapasCulturais\App;
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
}
