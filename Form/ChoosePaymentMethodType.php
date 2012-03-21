<?php

namespace JMS\Payment\CoreBundle\Form;

use JMS\Payment\CoreBundle\PluginController\Result;
use JMS\Payment\CoreBundle\Entity\PaymentInstruction;
use JMS\Payment\CoreBundle\Entity\ExtendedData;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\CallbackValidator;
use JMS\Payment\CoreBundle\PluginController\PluginControllerInterface;
use Symfony\Component\Form\Extension\Core\ChoiceList\ChoiceList;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\AbstractType;

/**
 * Form Type for Choosing a Payment Method.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class ChoosePaymentMethodType extends AbstractType
{
	private $pluginController;
    private $paymentMethods;

    public function __construct(PluginControllerInterface $pluginController, array $paymentMethods)
    {
        if (!$paymentMethods) {
            throw new \InvalidArgumentException('There is no payment method available. Did you forget to register concrete payment provider bundles such as JMSPaymentPaypalBundle?');
        }

        $this->pluginController = $pluginController;
        $this->paymentMethods = $paymentMethods;
    }

    public function buildForm(FormBuilder $builder, array $options)
    {
    	if (!isset($options['currency'])) {
    		throw new \InvalidArgumentException(sprintf('The option "currency" must be given for form type "%s".', $this->getName()));
    	}
    	if (!isset($options['amount'])) {
    		throw new \InvalidArgumentException(sprintf('The option "amount" must be given for form type "%s".', $this->getName()));
    	}

        $allowAllMethods = !isset($options['allowed_methods']);

        $options['available_methods'] = array();
        foreach ($this->paymentMethods as $method) {
            if (!$allowAllMethods && !in_array($method, $options['allowed_methods'], true)) {
                continue;
            }

            $options['available_methods'][] = $method;
        }

        if (!$options['available_methods']) {
            throw new \RuntimeException(sprintf('You have not selected any payment methods. Available methods: "%s"', implode(', ', $this->paymentMethods)));
        }

        $builder->add('method', 'choice', array(
            'choices' => $this->buildChoices($options['available_methods']),
            'expanded' => true,
            'index_strategy' => ChoiceList::COPY_CHOICE,
            'value_strategy' => ChoiceList::COPY_CHOICE,
        ));

        foreach ($options['available_methods'] as $method) {
            $methodOptions = isset($options['method_options'][$method]) ? $options['method_options'] : array();
            $builder->add('data_'.$method, $method, $methodOptions);
        }

        $self = $this;
        $builder->addValidator(new CallbackValidator(function($form) use ($self, $options) {
            $self->validate($form, $options);
        }));
        $builder->appendNormTransformer(new CallbackTransformer(
            function($data) use ($self, $options) {
                return $self->transform($data, $options);
            },
            function($data) use ($self, $options) {
                return $self->reverseTransform($data, $options);
            }
        ));
    }

    public function transform($data, array $options)
    {
        if (null === $data) {
            return null;
        }

        if ($data instanceof PaymentInstruction) {
        	$method = $data->getPaymentSystemName();
        	$methodData = array_map(function($v) { return $v[0]; }, $data->getExtendedData()->all());
        	if (isset($options['predefined_data'][$method])) {
        		$methodData = array_diff_key($methodData, $options['predefined_data'][$method]);
        	}

        	return array(
        		'method'        => $method,
        		'data_'.$method => $methodData,
        	);
        }

        throw new \RuntimeException(sprintf('Unsupported data of type "%s".', ('object' === $type = gettype($data)) ? get_class($data) : $type));
    }

    public function reverseTransform($data, array $options)
    {
        $method = isset($data['method']) ? $data['method'] : null;
        $data = isset($data['data_'.$method]) ? $data['data_'.$method] : array();

        $extendedData = new ExtendedData();
        foreach ($data as $k => $v) {
            $extendedData->set($k, $v);
        }

        if (isset($options['predefined_data'][$method])) {
        	if (!is_array($options['predefined_data'][$method])) {
        		throw new \RuntimeException(sprintf('"predefined_data" is expected to be an array for each method, but got "%s" for method "%s".', json_encode($options['extra_data'][$method]), $method));
        	}

        	foreach ($options['predefined_data'][$method] as $k => $v) {
        		$extendedData->set($k, $v);
        	}
        }

        return new PaymentInstruction($options['amount'], $options['currency'], $method, $extendedData);
    }

    public function validate(FormInterface $form, array $options)
    {
        $instruction = $form->getData();

        if (null === $instruction->getPaymentSystemName()) {
            $form->addError(new FormError('form.error.payment_method_required'));

            return;
        }
        if (!in_array($instruction->getPaymentSystemName(), $options['available_methods'], true)) {
            $form->addError(new FormError('form.error.invalid_payment_method'));

            return;
        }

        $result = $this->pluginController->checkPaymentInstruction($instruction);
        if (Result::STATUS_SUCCESS !== $result->getStatus()) {
        	$this->applyErrorsToForm($form, $result);

        	return;
        }

        $result = $this->pluginController->validatePaymentInstruction($instruction);
        if (Result::STATUS_SUCCESS !== $result->getStatus()) {
        	$this->applyErrorsToForm($form, $result);
        }
    }

    public function getDefaultOptions(array $options)
    {
    	return array(
    		'currency'        => null,
    		'amount'          => null,
    		'predefined_data' => array(),
    	);
    }

    public function getName()
    {
        return 'jms_choose_payment_method';
    }

    private function applyErrorsToForm(FormInterface $form, Result $result)
    {
       	$ex = $result->getPluginException();

       	$globalErrors = $ex->getGlobalErrors();
       	$dataErrors = $ex->getDataErrors();

       	// add a generic error message
       	if (!$dataErrors && !$globalErrors) {
       		$form->addError(new FormError('form.error.invalid_payment_instruction'));

       		return;
      	}

      	foreach ($globalErrors as $error) {
      		$form->addError(new FormError($error));
      	}

      	foreach ($dataErrors as $field => $error) {
      		$form->get($field)->addError(new FormError($error));
      	}
    }

    private function buildChoices(array $methods)
    {
        $choices = array();
        foreach ($methods as $method) {
            $choices[$method] = 'form.label.'.$method;
        }

        return $choices;
    }
}