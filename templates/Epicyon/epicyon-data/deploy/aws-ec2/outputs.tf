output "epicyon_domain" {
  description = "The name of the record"
  value       = format("https://%s", var.domain)
}

output "ipv4_address" {
  description = "The instance ip"
  value       = aws_instance.epicyon_web.public_ip
}
