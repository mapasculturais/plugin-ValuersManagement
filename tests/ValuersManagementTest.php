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
}
