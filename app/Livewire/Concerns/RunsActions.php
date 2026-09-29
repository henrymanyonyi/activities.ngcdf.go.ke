<?php

namespace App\Livewire\Concerns;

use App\Exceptions\ActivityWorkflowException;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Runs a service call and turns the outcome into a toast. Business-rule and
 * permission failures become an error toast instead of an exception page.
 */
trait RunsActions
{
    /** @return mixed the closure's result, or null when it failed */
    protected function attempt(Closure $action, ?string $success = null): mixed
    {
        try {
            $result = $action();
        } catch (ActivityWorkflowException|InvalidArgumentException $e) {
            $this->toast($e->getMessage(), 'error');

            return null;
        } catch (AuthorizationException) {
            $this->toast('You are not permitted to do this.', 'error');

            return null;
        } catch (HttpException $e) {
            $this->toast($e->getMessage() ?: 'That action could not be completed.', 'error');

            return null;
        }

        if ($success) {
            $this->toast($success);
        }

        return $result ?? true;
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('notify', message: $message, type: $type);
    }

    /** @param  list<string>  $warnings */
    protected function warn(array $warnings): void
    {
        if ($warnings !== []) {
            $this->toast(implode(' ', $warnings), 'warning');
        }
    }
}
