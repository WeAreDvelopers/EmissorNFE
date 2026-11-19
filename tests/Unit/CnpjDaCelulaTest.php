<?php

namespace Tests\Unit;

use App\Support\PlanilhaModelo;
use PHPUnit\Framework\TestCase;

class CnpjDaCelulaTest extends TestCase
{
    public function test_aceita_texto_com_mascara_numero_e_notacao_cientifica(): void
    {
        $this->assertSame('11222333000181', PlanilhaModelo::cnpjDaCelula('11.222.333/0001-81'));
        $this->assertSame('11222333000181', PlanilhaModelo::cnpjDaCelula(' 11222333000181 '));
        $this->assertSame('11222333000181', PlanilhaModelo::cnpjDaCelula(11222333000181));
        $this->assertSame('11222333000181', PlanilhaModelo::cnpjDaCelula(1.1222333000181E+13));
        $this->assertSame('01222333000181', PlanilhaModelo::cnpjDaCelula(1222333000181));   // zero à esquerda perdido pelo Excel
        $this->assertSame('', PlanilhaModelo::cnpjDaCelula(null));
    }
}
