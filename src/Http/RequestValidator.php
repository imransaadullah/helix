<?php

namespace Helix\Http;

class RequestValidator
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function validate(array $rules): array
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $rule) {
            $value = $this->request->input($field);
            $rules = is_array($rule) ? $rule : explode('|', $rule);

            foreach ($rules as $singleRule) {
                $result = $this->validateRule($field, $value, $singleRule);
                if ($result !== true) {
                    $errors[$field][] = $result;
                    continue 2;
                }
            }

            $validated[$field] = $value;
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    private function validateRule(string $field, mixed $value, string $rule): bool|string
    {
        $params = explode(':', $rule, 2);
        $ruleName = $params[0];
        $ruleParams = $params[1] ?? null;

        switch ($ruleName) {
            case 'required':
                if (empty($value)) {
                    return "The $field field is required";
                }
                break;
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return "The $field must be a valid email address";
                }
                break;
            case 'min':
                if (strlen($value) < $ruleParams) {
                    return "The $field must be at least $ruleParams characters";
                }
                break;
            case 'max':
                if (strlen($value) > $ruleParams) {
                    return "The $field may not be greater than $ruleParams characters";
                }
                break;
            case 'numeric':
                if (!is_numeric($value)) {
                    return "The $field must be a number";
                }
                break;
            // Add more validation rules as needed
            default:
                return true;
        }

        return true;
    }
}