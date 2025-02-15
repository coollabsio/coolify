import type { InputComponents } from './interface'
import AutoFormFieldArray from './AutoFormFieldArray.vue'
import AutoFormFieldBoolean from './AutoFormFieldBoolean.vue'
import AutoFormFieldDate from './AutoFormFieldDate.vue'
import AutoFormFieldEnum from './AutoFormFieldEnum.vue'
import AutoFormFieldFile from './AutoFormFieldFile.vue'
import AutoFormFieldInput from './AutoFormFieldInput.vue'
import AutoFormFieldNumber from './AutoFormFieldNumber.vue'
import AutoFormFieldObject from './AutoFormFieldObject.vue'

export const INPUT_COMPONENTS: InputComponents = {
  date: AutoFormFieldDate,
  select: AutoFormFieldEnum,
  radio: AutoFormFieldEnum,
  checkbox: AutoFormFieldBoolean,
  switch: AutoFormFieldBoolean,
  textarea: AutoFormFieldInput,
  number: AutoFormFieldNumber,
  string: AutoFormFieldInput,
  file: AutoFormFieldFile,
  array: AutoFormFieldArray,
  object: AutoFormFieldObject,
}

/**
 * Define handlers for specific Zod types.
 * You can expand this object to support more types.
 */
export const DEFAULT_ZOD_HANDLERS: {
  [key: string]: keyof typeof INPUT_COMPONENTS
} = {
  ZodString: 'string',
  ZodBoolean: 'checkbox',
  ZodDate: 'date',
  ZodEnum: 'select',
  ZodNativeEnum: 'select',
  ZodNumber: 'number',
  ZodArray: 'array',
  ZodObject: 'object',
}
