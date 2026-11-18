<?php

namespace App\Tests\Coroner;

use App\Coroner\Mcp\AnalysisService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;

final class AnalysisServiceTest extends TestCase
{
    public function testDiagnoseReturnsParsedJsonFromTheAnalyst(): void
    {
        $analyst = $this->createAnalystStub(<<<'JSON'
            Here is my verdict:
            ```json
            {"failure_class":"bug","root_cause":"null stock count","evidence":"TypeError","recommended_action":"fix_then_retry","fix_suggestion":"guard missing keys","confidence":0.9}
            ```
            JSON);
        $service = new AnalysisService($analyst);

        $diagnosis = $service->diagnose(['exception_message' => 'TypeError...']);

        $this->assertSame('bug', $diagnosis['failure_class']);
        $this->assertSame('fix_then_retry', $diagnosis['recommended_action']);
        $this->assertSame(0.9, $diagnosis['confidence']);
        $this->assertArrayNotHasKey('raw_response', $diagnosis);
    }

    public function testDiagnoseFallsBackToUnknownWhenTheAnalystRambles(): void
    {
        $service = new AnalysisService($this->createAnalystStub('I am so sorry, I cannot help with that today.'));

        $diagnosis = $service->diagnose([]);

        $this->assertSame('unknown', $diagnosis['failure_class']);
        $this->assertArrayHasKey('raw_response', $diagnosis);
    }

    private function createAnalystStub(string $content): AgentInterface&MockObject
    {
        $result = $this->createStub(ExecutionInterface::class);
        $result->method('getContent')->willReturn($content);

        $analyst = $this->createMock(AgentInterface::class);
        $analyst->method('call')->willReturn($result);

        return $analyst;
    }
}
