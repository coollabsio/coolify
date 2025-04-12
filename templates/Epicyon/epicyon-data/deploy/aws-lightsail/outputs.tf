output "aws_lightsail_domain" {
  description = "The name of the record"
  value       = format("https://%s", var.epicyon_sub_domain)
}
output "ipv4_address" {
  description = "The instance ip"
  value       = aws_lightsail_instance.epicyon.public_ip_address
}
