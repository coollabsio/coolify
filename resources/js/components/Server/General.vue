<script setup lang="ts">
import { FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Button } from '@/components/ui/button';
import { toTypedSchema } from '@vee-validate/zod';
import { useForm } from 'vee-validate';
import { z } from 'zod';

const schema = z.object({
  name: z.string().min(1, { message: 'Name is required' }).max(255, { message: 'Name must be less than 255 characters' }).describe('Name'),
  description: z.string().max(255, { message: 'Description must be less than 255 characters' }).describe('Description').optional(),
  ip: z.string().min(1, { message: 'IP is required' }).max(255, { message: 'IP must be less than 255 characters' }).describe('IP'),
  port: z.number().min(1, { message: 'Port is required' }).max(65535, { message: 'Port must be less than 65535' }).describe('Port'),
  user: z.string().min(1, { message: 'User is required' }).max(255, { message: 'User must be less than 255 characters' }).describe('User'),
  wildcard_domain: z.string().min(1, { message: 'Wildcard Domain is required' }).max(255, { message: 'Wildcard Domain must be less than 255 characters' }).describe('Wildcard Domain'),
  server_timezone: z.string().min(1, { message: 'Server Timezone is required' }).max(255, { message: 'Server Timezone must be less than 255 characters' }).describe('Server Timezone'),
})

const form = useForm({
  validationSchema: toTypedSchema(schema),
})

</script>

<template>
  <h2 class="text-2xl font-bold pb-2">
    General
  </h2>
  <form class="space-y-4">
    <div class="flex gap-2 w-full">
      <FormField v-slot="{ componentField }" name="name">
        <FormItem class="w-full" v-auto-animate>
          <FormLabel>Name</FormLabel>
          <FormControl>
            <Input type="text" placeholder="Name" v-bind="componentField" />
          </FormControl>
          <FormMessage />
        </FormItem>
      </FormField>
      <FormField v-slot="{ componentField }" name="description">
        <FormItem class="w-full" v-auto-animate>
          <FormLabel>Description</FormLabel>
          <FormControl>
            <Input type="text" placeholder=" Description" v-bind="componentField" />
          </FormControl>
        </FormItem>
      </FormField>
    </div>
    <Button type="submit">Save</Button>
  </form>
  <Separator class="my-4" />
  <h2 class="text-2xl font-bold pb-2">
    Advanced
  </h2>
  <form class="space-y-4">
    <div class="flex gap-2 w-full">
      <FormField v-slot="{ componentField }" name="wildcard_domain">
        <FormItem class="w-full" v-auto-animate>
          <FormLabel>Wildcard Domain</FormLabel>
          <FormControl>
            <Input type="text" placeholder="Wildcard Domain" v-bind="componentField" />
          </FormControl>
          <FormMessage />
        </FormItem>
      </FormField>
      <FormField v-slot="{ componentField }" name="server_timezone">
        <FormItem class="w-full" v-auto-animate>
          <FormLabel>Server Timezone</FormLabel>
          <FormControl>
            <Input type="text" placeholder="Server Timezone" v-bind="componentField" />
          </FormControl>
        </FormItem>
      </FormField>
    </div>
    <Button type="submit">Save</Button>
  </form>
</template>