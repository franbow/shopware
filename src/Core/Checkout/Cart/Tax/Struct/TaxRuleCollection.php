<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Tax\Struct;

use Shopware\Core\Framework\Struct\Collection;

/**
 * @method void         set(string $key, TaxRule $entity)
 * @method TaxRule[]    getIterator()
 * @method TaxRule[]    getElements()
 * @method TaxRule|null first()
 * @method TaxRule|null last()
 */
class TaxRuleCollection extends Collection
{
    public function add($taxRule): void
    {
        $this->set($this->getKey($taxRule), $taxRule);
    }

    public function removeElement(TaxRule $taxRule): void
    {
        $this->remove($this->getKey($taxRule));
    }

    public function exists(TaxRule $taxRule): bool
    {
        return $this->has($this->getKey($taxRule));
    }

    public function get($taxRate): ?TaxRule
    {
        $key = (string) $taxRate;

        if ($this->has($key)) {
            return $this->elements[$key];
        }

        return null;
    }

    public function merge(self $rules): self
    {
        $new = new self($this->elements);

        $rules->map(
            function (TaxRule $rule) use ($new) {
                if (!$new->exists($rule)) {
                    $new->add($rule);
                }
            }
        );

        return $new;
    }

    protected function getExpectedClass(): ?string
    {
        return TaxRule::class;
    }

    protected function getKey(TaxRule $element): string
    {
        return (string) $element->getTaxRate();
    }
}
